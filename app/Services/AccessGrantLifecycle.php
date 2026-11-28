<?php

namespace App\Services;

use App\Models\AccessGrant;
use App\Models\Company;
use App\Models\Job;
use App\Models\Order;
use App\Models\User;
use App\Support\ApplicationRestriction;
use App\Support\GrantDuration;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccessGrantLifecycle
{
    // Controlled reasons keep applicant/contact text out of the audit trail.
    public const REASONS = ['company_request', 'security', 'payment_review', 'administrative', 'other'];

    public function grant(User $actor, string $id): AccessGrant
    {
        Gate::forUser($actor)->authorize('viewAny', Order::class);
        abort_unless(filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 403);
        $grant = AccessGrant::query()->with(['company:id,name', 'job:id,title'])->find($id);
        abort_unless($grant !== null, 403);

        return $grant;
    }

    /** @return Collection<int, AccessGrant> */
    public function affected(AccessGrant $grant): Collection
    {
        return AccessGrant::query()->where('company_id', $grant->company_id)->where('job_id', $grant->job_id)
            ->where('status', 'active')->where('ends_at', '>', now()->format('Y-m-d H:i:s.uP'))->orderBy('id')->get();
    }

    /** @param Collection<int, AccessGrant> $grants */
    public function previewSignature(User $actor, AccessGrant $scope, Collection $grants): string
    {
        $rows = $grants->map(fn (AccessGrant $grant): array => [$grant->id, $grant->order_id, $grant->getRawOriginal('starts_at'), $grant->getRawOriginal('ends_at')])->all();

        return hash_hmac('sha256', json_encode([$actor->id, $scope->company_id, $scope->job_id, $rows], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function revoke(User $actor, string $id, array $input, Request $request): int
    {
        $scope = $this->grant($actor, $id);
        $data = Validator::make($input, ['reason' => ['required', Rule::in(self::REASONS)], 'preview_signature' => ['required', 'string', 'size:64']])->validate();
        $operation = (string) Str::ulid();

        return DB::transaction(function () use ($actor, $scope, $data, $request, $operation): int {
            $fresh = User::query()->whereKey($actor->id)->sharedLock()->firstOrFail();
            Gate::forUser($fresh)->authorize('viewAny', Order::class);
            // All lifecycle writers serialize on the job, then orders, then grants.
            Job::withTrashed()->whereKey($scope->job_id)->lockForUpdate()->firstOrFail();
            Order::query()->whereIn('id', AccessGrant::query()->where('company_id', $scope->company_id)->where('job_id', $scope->job_id)->select('order_id'))
                ->orderBy('id')->lockForUpdate()->get(['id']);
            $grants = $this->affected($scope);
            abort_unless(hash_equals($this->previewSignature($actor, $scope, $grants), $data['preview_signature']), 409, __('grants.changed'));
            foreach ($grants as $grant) {
                $grant->forceFill(['status' => 'revoked', 'revoked_at' => now('UTC'), 'revoked_by' => $actor->id, 'revoked_reason' => $data['reason']])->save();
                app(RecordAudit::class)->grantRevoked($actor, $grant, $data['reason'], $operation, $request);
            }

            return $grants->count();
        }, 3);
    }

    public function renew(User $actor, string $id, array $input): Order
    {
        $scope = $this->grant($actor, $id);

        return DB::transaction(function () use ($actor, $scope, $input): Order {
            $fresh = User::query()->whereKey($actor->id)->sharedLock()->firstOrFail();
            Gate::forUser($fresh)->authorize('viewAny', Order::class);
            $job = Job::withTrashed()->whereKey($scope->job_id)->lockForUpdate()->firstOrFail();
            $company = Company::withTrashed()->whereKey($scope->company_id)->sharedLock()->first();
            abort_unless($company && ! $company->deleted_at && ! $job->deleted_at
                && in_array($job->status->value, ['published', 'paused', 'expired', 'closed'], true)
                && ! app(ApplicationRestriction::class)->blocked($scope->company_id, $scope->job_id), 409, __('grants.ineligible'));
            abort_unless($this->tail($scope->company_id, $scope->job_id) !== null, 409, __('grants.new_purchase'));
            $pending = Order::query()->where('company_id', $scope->company_id)->where('job_id', $scope->job_id)
                ->where('type', 'access_grant')->where('status', 'pending')->lockForUpdate()->first();
            if ($pending) {
                abort_unless($pending->is_renewal, 409, __('grants.pending_purchase'));

                return $pending;
            }
            abort_if(Order::query()->where('company_id', $scope->company_id)->whereNull('job_id')->where('type', 'access_grant')->where('status', 'pending')->exists(), 409, __('grants.pending_purchase'));
            $method = $input['method'] ?? null;
            if ($method === 'bank_transfer') {
                // Bank renewals always snapshot server configuration, never submitted days.
                unset($input['duration_days'], $input['reason']);
            }
            $data = Validator::make($input, ['method' => ['required', Rule::in(Order::METHODS)], 'reason' => ['nullable', 'string', 'max:2000'],
                'duration_days' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value) && ! is_string($value)) {
                        $fail(__('payments.invalid'));
                    }
                }, 'min:1', 'max:'.app(GrantDuration::class)->bounds()['max']]])->validate();
            $attributes = ['company_id' => $scope->company_id, 'job_id' => $scope->job_id, 'type' => 'access_grant',
                'is_renewal' => true, 'status' => 'pending', 'method' => $data['method'], 'payment_reference' => 'VE'.Str::ulid()];
            if ($data['method'] === 'bank_transfer') {
                $settings = app(AccessPaymentSettings::class)->snapshot();
                $attributes += ['amount' => $settings['amount'], 'duration_days' => $settings['duration_days'], 'payment_instructions' => $settings['instructions']];
            } else {
                Validator::make($data, ['reason' => ['required', 'string', 'max:2000']])->validate();
                $attributes += ['amount' => 0, 'reason' => $data['reason'], 'duration_days' => $data['duration_days'] ?? null];
            }
            $order = new Order;
            $order->forceFill($attributes)->save();

            return $order;
        }, 3);
    }

    public function tail(int $companyId, int $jobId): ?AccessGrant
    {
        return AccessGrant::query()->where('company_id', $companyId)->where('job_id', $jobId)->where('status', '<>', 'revoked')
            ->orderByDesc('ends_at')->orderByDesc('id')->first();
    }

    /** Caller confirming a renewal must already hold the job lock. */
    public function start(Order $order, CarbonImmutable $now): CarbonImmutable
    {
        if (! $order->is_renewal) {
            return $now;
        }
        $tail = $this->tail($order->company_id, $order->job_id);
        abort_unless($tail !== null, 409, __('grants.new_purchase'));

        return $tail->ends_at->gt($now) ? $tail->ends_at : $now;
    }
}

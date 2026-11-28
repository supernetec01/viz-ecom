<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Enums\Role;
use App\Exceptions\PaymentConfirmationRejected;
use App\Models\Company;
use App\Models\Job;
use App\Models\JobBoost;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Support\ApplicationRestriction;
use App\Support\PaymentTransactionIdentity;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BoostOrders
{
    /** @return Builder<Job> */
    public function jobs(User $actor): Builder
    {
        Gate::forUser($actor)->authorize('viewAny', Job::class);

        return Job::visibleTo($actor)->whereIn('status', ['published', 'paused'])->where('published_at', '<=', now())
            ->where('expires_at', '>', now())->select(['jobs.id', 'jobs.company_id', 'jobs.title', 'jobs.status', 'jobs.expires_at'])->with('company:id,name');
    }

    public function order(User $actor, string $id): Order
    {
        Gate::forUser($actor)->authorize('viewAny', Job::class);
        abort_unless(filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 403);
        $order = Order::query()->where('type', 'job_boost')->whereIn('company_id', Company::visibleTo($actor)->select('companies.id'))
            ->with(['company:id,name', 'job:id,title'])->find($id);
        abort_unless($order !== null, 403);

        return $order;
    }

    public function request(User $actor, string $jobId, array $input): Order
    {
        Gate::forUser($actor)->authorize('viewAny', Job::class);
        abort_unless(filter_var($jobId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 403);

        return DB::transaction(function () use ($actor, $jobId, $input): Order {
            $fresh = User::query()->whereKey($actor->id)->sharedLock()->firstOrFail();
            Gate::forUser($fresh)->authorize('viewAny', Job::class);
            $job = $this->jobs($fresh)->whereKey($jobId)->lockForUpdate()->first();
            abort_unless($job !== null, 403);
            if ($fresh->role === Role::Staff) {
                abort_unless(DB::table('staff_company')->where('company_id', $job->company_id)->where('user_id', $fresh->id)->sharedLock()->first() !== null, 403);
            }
            $pending = Order::query()->where('company_id', $job->company_id)->where('job_id', $job->id)->where('type', 'job_boost')
                ->where('status', 'pending')->lockForUpdate()->first();
            if ($pending) {
                return $pending;
            }
            $data = Validator::make($input, ['plan_id' => ['required', 'integer', 'min:1'], 'method' => ['required', Rule::in(Order::METHODS)],
                'reason' => ['required_unless:method,bank_transfer', 'nullable', 'string', 'max:2000']])->validate();
            $plan = Plan::query()->whereKey($data['plan_id'])->where('is_active', true)->sharedLock()->first();
            abort_unless($plan !== null, 409, __('boosts.plan_unavailable'));
            $this->eligible($job->id, $job->company_id, CarbonImmutable::now('UTC')->addDays($plan->duration_days));
            $order = new Order;
            $order->forceFill(['company_id' => $job->company_id, 'job_id' => $job->id, 'type' => 'job_boost', 'status' => 'pending',
                'method' => $data['method'], 'reason' => $data['method'] === 'bank_transfer' ? null : $data['reason'],
                'amount' => $data['method'] === 'bank_transfer' ? $plan->price : 0, 'boost_plan_id' => $plan->id,
                'boost_plan_name' => $plan->name, 'boost_days' => $plan->duration_days, 'boost_priority' => $plan->priority,
                'requested_by' => $actor->id, 'payment_reference' => 'VE'.Str::ulid(),
                'payment_instructions' => $data['method'] === 'bank_transfer' ? app(AccessPaymentSettings::class)->bankInstructions() : null])->save();

            return $order;
        }, 3);
    }

    /** @return array{order: Order, boost: JobBoost, created: bool} */
    public function confirm(User $actor, string $id, array $input, Request $request): array
    {
        Gate::forUser($actor)->authorize('viewAny', Order::class);
        abort_unless(filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 403);
        $operation = (string) Str::ulid();
        try {
            return DB::transaction(function () use ($actor, $id, $input, $request, $operation): array {
                $fresh = User::query()->whereKey($actor->id)->sharedLock()->firstOrFail();
                Gate::forUser($fresh)->authorize('viewAny', Order::class);
                $hint = Order::query()->whereKey($id)->where('type', 'job_boost')->first(['id', 'job_id']);
                abort_unless($hint !== null, 403);
                $job = $hint->job_id ? Job::withTrashed()->whereKey($hint->job_id)->lockForUpdate()->first() : null;
                $order = Order::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $boost = JobBoost::query()->where('order_id', $order->id)->lockForUpdate()->first();
                if ($order->status === 'paid') {
                    if (! $boost || $boost->company_id !== $order->company_id || $boost->job_id !== $order->job_id) {
                        throw new PaymentConfirmationRejected('paid_without_boost');
                    }

                    return ['order' => $order, 'boost' => $boost, 'created' => false];
                }
                if ($order->status !== 'pending' || $boost || ! $job || $job->company_id !== $order->company_id || ! $order->boost_plan_id || ! $order->boost_days || ! $order->boost_priority) {
                    throw new PaymentConfirmationRejected('scope_missing');
                }
                $at = CarbonImmutable::now('UTC');
                $end = $at->addDays($order->boost_days);
                $this->eligible($job->id, $order->company_id, $end);
                $data = Validator::make($input, ['starts_at' => ['prohibited'], 'ends_at' => ['prohibited'],
                    'received_amount' => ['required', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                        if (! is_int($value) && ! is_string($value)) {
                            $fail(__('payments.invalid_amount'));
                        }
                    }, $order->method === 'bank_transfer' ? 'min:1' : 'min:0'],
                    'transaction_reference' => ['nullable', 'string', 'max:255']])->validate();
                if ((string) $data['received_amount'] !== (string) $order->amount) {
                    throw new PaymentConfirmationRejected('amount_mismatch', ['order_amount' => $order->amount, 'received_amount' => (int) $data['received_amount']]);
                }
                $reference = null;
                $bankKey = null;
                if ($order->method === 'bank_transfer') {
                    $reference = PaymentTransactionIdentity::normalize($data['transaction_reference'] ?? '');
                    Validator::make(['transaction_reference' => $reference], ['transaction_reference' => ['required', 'string', 'max:255']])->validate();
                    if (! is_array($order->payment_instructions)) {
                        throw new PaymentConfirmationRejected('bank_missing');
                    }
                    $bankKey = PaymentTransactionIdentity::bankKey($order->payment_instructions);
                } elseif ($order->amount !== 0 || trim($order->reason ?? '') === '') {
                    throw new PaymentConfirmationRejected('zero_reason');
                }
                $order->forceFill(['status' => 'paid', 'confirmed_by' => $actor->id, 'confirmed_at' => $at,
                    'transaction_reference' => $reference, 'payment_bank_key' => $bankKey])->save();
                $boost = new JobBoost;
                $boost->forceFill(['company_id' => $order->company_id, 'job_id' => $order->job_id, 'plan_id' => $order->boost_plan_id,
                    'order_id' => $order->id, 'starts_at' => $at, 'ends_at' => $end, 'priority' => $order->boost_priority, 'created_by' => $actor->id])->save();
                app(RecordAudit::class)->boostConfirmed($actor, $order, $boost, $operation, $request);

                return ['order' => $order, 'boost' => $boost, 'created' => true];
            }, 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505' || ! str_contains($exception->getMessage(), 'orders_paid_bank_transaction_unique')) {
                throw $exception;
            }
            app(RecordAudit::class)->paymentRejected($actor, (int) $id, $operation, $request, 'duplicate_reference');
            throw new PaymentConfirmationRejected('duplicate_reference');
        } catch (PaymentConfirmationRejected $exception) {
            app(RecordAudit::class)->paymentRejected($actor, (int) $id, $operation, $request, $exception->reason, $exception->details);
            throw $exception;
        }
    }

    private function eligible(int $jobId, int $companyId, CarbonImmutable $end): void
    {
        $job = Job::withTrashed()->whereKey($jobId)->first();
        $company = Company::withTrashed()->whereKey($companyId)->sharedLock()->first(['id', 'deleted_at']);
        if (! $job || $job->company_id !== $companyId || $job->deleted_at || ! $company || $company->deleted_at
            || ! in_array($job->status, [JobStatus::Published, JobStatus::Paused], true) || ! $job->published_at || $job->published_at->isFuture()
            || ! $job->expires_at || $end->gt($job->expires_at) || app(ApplicationRestriction::class)->blocked($companyId, $jobId)) {
            throw new PaymentConfirmationRejected('boost_ineligible');
        }
    }
}

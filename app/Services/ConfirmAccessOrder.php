<?php

namespace App\Services;

use App\Exceptions\PaymentConfirmationRejected;
use App\Models\AccessGrant;
use App\Models\Company;
use App\Models\Job;
use App\Models\Order;
use App\Models\User;
use App\Support\ApplicationRestriction;
use App\Support\GrantDuration;
use App\Support\PaymentTransactionIdentity;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ConfirmAccessOrder
{
    public function order(User $actor, string $id): Order
    {
        Gate::forUser($actor)->authorize('viewAny', Order::class);
        abort_unless(filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 403);
        $order = Order::query()->whereKey($id)->where('type', 'access_grant')->with(['company:id,name', 'job:id,title', 'accessGrant'])->first();
        abort_unless($order !== null, 403);

        return $order;
    }

    /** @return array{order: Order, grant: AccessGrant, created: bool} */
    public function confirm(User $actor, string $id, array $input, Request $request): array
    {
        Gate::forUser($actor)->authorize('viewAny', Order::class);
        abort_unless(filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 403);
        $operation = (string) Str::ulid();
        try {
            return DB::transaction(function () use ($actor, $id, $input, $request, $operation): array {
                $currentActor = User::query()->whereKey($actor->id)->sharedLock()->first();
                abort_unless($currentActor !== null, 403);
                Gate::forUser($currentActor)->authorize('viewAny', Order::class);
                $hint = Order::query()->whereKey($id)->first(['id', 'job_id']);
                abort_unless($hint !== null, 403);
                $job = $hint->job_id ? Job::withTrashed()->whereKey($hint->job_id)->lockForUpdate()->first(['id', 'company_id', 'status', 'deleted_at']) : null;
                $order = Order::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($order->type !== 'access_grant' || $order->job_id === null || ! $job || $job->id !== $order->job_id || $job->company_id !== $order->company_id) {
                    throw new PaymentConfirmationRejected('scope_missing');
                }
                $existing = AccessGrant::query()->where('order_id', $order->id)->lockForUpdate()->first();
                if ($order->status === 'paid') {
                    if (! $existing || $existing->company_id !== $order->company_id || $existing->job_id !== $order->job_id) {
                        throw new PaymentConfirmationRejected('paid_without_grant');
                    }

                    return ['order' => $order, 'grant' => $existing, 'created' => false];
                }
                $company = Company::withTrashed()->whereKey($order->company_id)->sharedLock()->first(['id', 'deleted_at']);
                if ($order->status !== 'pending' || $existing || ! $company || $company->deleted_at || $job->deleted_at
                    || ! in_array($job->status->value, ['published', 'paused', 'expired', 'closed'], true)
                    || app(ApplicationRestriction::class)->blocked($order->company_id, $order->job_id)) {
                    throw new PaymentConfirmationRejected('ineligible');
                }
                $data = Validator::make($input, [
                    'starts_at' => ['prohibited'], 'ends_at' => ['required', 'string', 'max:40'],
                    'received_amount' => ['required', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                        if (! is_int($value) && ! is_string($value)) {
                            $fail(__('payments.invalid_amount'));
                        }
                    }, $order->method === 'bank_transfer' ? 'min:1' : 'min:0'],
                    'transaction_reference' => ['nullable', 'string', 'max:255'],
                ])->validate();
                if ((string) $data['received_amount'] !== (string) $order->amount) {
                    throw new PaymentConfirmationRejected('amount_mismatch', ['order_amount' => $order->amount, 'received_amount' => (int) $data['received_amount']]);
                }
                $reference = null;
                $bankKey = null;
                if ($order->method === 'bank_transfer') {
                    $reference = PaymentTransactionIdentity::normalize($data['transaction_reference'] ?? '');
                    Validator::make(['transaction_reference' => $reference], ['transaction_reference' => ['required', 'string', 'max:255']])->validate();
                    if ($order->amount < 1 || ! is_array($order->payment_instructions)) {
                        throw new PaymentConfirmationRejected('bank_missing');
                    }
                    $bankKey = PaymentTransactionIdentity::bankKey($order->payment_instructions);
                } elseif (! in_array($order->method, ['gift', 'free_trial'], true) || $order->amount !== 0 || trim($order->reason ?? '') === '') {
                    throw new PaymentConfirmationRejected('zero_reason');
                }
                $at = CarbonImmutable::now('UTC');
                $lifecycle = app(AccessGrantLifecycle::class);
                $tail = $order->is_renewal ? $lifecycle->tail($order->company_id, $order->job_id) : null;
                if ($order->is_renewal && ! $tail) {
                    throw new PaymentConfirmationRejected('renewal_missing');
                }
                $start = $tail && $tail->ends_at->gt($at) ? $tail->ends_at : $at;
                $expiry = app(GrantDuration::class)->select($order, $actor->id, $input, $start);
                $end = $expiry['end'];
                if (AccessGrant::query()->where('company_id', $order->company_id)->where('job_id', $order->job_id)->where('status', 'active')
                    ->where('starts_at', '<', $end->format('Y-m-d H:i:s.uP'))->where('ends_at', '>', $start->format('Y-m-d H:i:s.uP'))
                    ->lockForUpdate()->first(['id'])) {
                    throw new PaymentConfirmationRejected('overlap');
                }
                $order->forceFill(['status' => 'paid', 'confirmed_by' => $actor->id, 'confirmed_at' => $at,
                    'transaction_reference' => $reference, 'payment_bank_key' => $bankKey])->save();
                $grant = new AccessGrant;
                $grant->forceFill(['order_id' => $order->id, 'company_id' => $order->company_id, 'job_id' => $order->job_id,
                    'previous_grant_id' => $tail?->id, 'starts_at' => $start, 'ends_at' => $end, 'created_by' => $actor->id, 'status' => 'active'])->save();
                $audit = app(RecordAudit::class);
                $audit->paymentConfirmed($actor, $order, $grant, $operation, $request);
                if ($expiry['overridden']) {
                    $audit->expiryOverride($actor, $order, $expiry['default'], $end, $operation, $request);
                }

                return ['order' => $order, 'grant' => $grant, 'created' => true];
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
}

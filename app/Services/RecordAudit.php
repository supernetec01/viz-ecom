<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AccessGrant;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RecordAudit
{
    public function record(User $actor, string $action, string $id, int $companyId, Request $request, array $metadata = []): void
    {
        $status = ['new', 'viewed', 'interview', 'hired', 'rejected'];
        if (! in_array($action, ['application.viewed_detail', 'application.status_changed', 'application.note_updated', 'application.file_viewed', 'application.file_downloaded'], true)
            || ($action === 'application.status_changed'
                ? (array_keys($metadata) !== ['old', 'new'] || ! in_array($metadata['old'], $status, true) || ! in_array($metadata['new'], $status, true))
                : (in_array($action, ['application.file_viewed', 'application.file_downloaded'], true)
                    ? (array_keys($metadata) !== ['file_id'] || ! is_int($metadata['file_id']) || $metadata['file_id'] < 1)
                    : $metadata !== []))) {
            throw new InvalidArgumentException('Invalid audit event.');
        }
        $this->append($actor, $action, 'application', $id, $companyId, $request, $metadata);
    }

    public function login(User $actor, Request $request): void
    {
        $this->append($actor, 'auth.login', 'user', (string) $actor->id, null, $request, []);
    }

    public function processingRetry(User $actor, int $fileId, string $stage, Request $request): void
    {
        abort_unless(User::query()->whereKey($actor->id)->where('role', Role::Admin->value)->where('status', UserStatus::Active->value)->exists(), 403);
        if ($fileId < 1 || ! in_array($stage, ['scan', 'preview'], true)) {
            throw new InvalidArgumentException('Invalid audit event.');
        }
        $this->append($actor, 'file.processing_retry', 'file', (string) $fileId, null, $request, ['stage' => $stage]);
    }

    public function blocked(User $actor, string $id, int $companyId, string $bucket, int $limit, Request $request): void
    {
        if (! in_array($bucket, ['file', 'details'], true) || $limit < 1) {
            throw new InvalidArgumentException('Invalid audit event.');
        }
        $this->append($actor, 'application.access_blocked', 'application', $id, $companyId, $request,
            ['reason' => 'rate_limit', 'bucket' => $bucket, 'limit' => $limit]);
    }

    /** Hook for T38/T39: call inside their mutation transaction, with a stable operation ULID. */
    public function grantChange(User $actor, string $action, int $grantId, string $operation, Request $request): void
    {
        abort_unless(User::query()->whereKey($actor->id)->where('role', Role::Admin->value)->where('status', UserStatus::Active->value)->exists(), 403);
        if (! in_array($action, ['access_grant.issued', 'access_grant.revoked'], true) || ! Str::isUlid($operation)) {
            throw new InvalidArgumentException('Invalid audit event.');
        }
        DB::transaction(function () use ($actor, $action, $grantId, $operation, $request) {
            $grant = AccessGrant::query()->whereKey($grantId)->lockForUpdate()->first();
            abort_unless($grant !== null && $grant->status === ($action === 'access_grant.issued' ? 'active' : 'revoked'), 403);
            $key = $action.':'.$operation;
            $previous = DB::table('audit_logs')->where('operation_key', $key)->first(['actor_id', 'subject_id']);
            if ($previous) {
                if ($previous->actor_id !== $actor->id || $previous->subject_id !== (string) $grantId) {
                    throw new InvalidArgumentException('Conflicting audit operation.');
                }

                return;
            }
            $this->append($actor, $action, 'access_grant', (string) $grantId, $grant->company_id, $request,
                ['job_id' => $grant->job_id, 'order_id' => $grant->order_id], $key);
        });
    }

    public function paymentConfirmed(User $actor, Order $order, AccessGrant $grant, string $operation, Request $request): void
    {
        $this->requirePaymentAdmin($actor, $operation);
        DB::transaction(function () use ($actor, $order, $grant, $operation, $request): void {
            $savedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $savedGrant = AccessGrant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();
            if ($savedOrder->status !== 'paid' || $savedOrder->confirmed_by !== $actor->id || $savedGrant->order_id !== $savedOrder->id
                || $savedGrant->company_id !== $savedOrder->company_id || $savedGrant->job_id !== $savedOrder->job_id || $savedGrant->created_by !== $actor->id) {
                throw new InvalidArgumentException('Invalid payment audit binding.');
            }
            $metadata = ['order_id' => $savedOrder->id, 'grant_id' => $savedGrant->id, 'company_id' => $savedOrder->company_id,
                'job_id' => $savedOrder->job_id, 'starts_at' => $savedGrant->starts_at->toIso8601String(), 'ends_at' => $savedGrant->ends_at->toIso8601String()];
            $this->appendPaymentOnce($actor, 'order.paid_confirmed', 'order', (string) $savedOrder->id, $savedOrder->company_id, $request, $metadata, $operation);
            if ($savedGrant->previous_grant_id !== null) {
                $metadata['previous_grant_id'] = $savedGrant->previous_grant_id;
            }
            $this->appendPaymentOnce($actor, 'access_grant.created', 'access_grant', (string) $savedGrant->id, $savedOrder->company_id, $request, $metadata, $operation);
        });
    }

    public function expiryOverride(User $actor, Order $order, ?CarbonImmutable $default, CarbonImmutable $selected, string $operation, Request $request): void
    {
        $this->requirePaymentAdmin($actor, $operation);
        $this->appendPaymentOnce($actor, 'order.expiry_overridden', 'order', (string) $order->id, $order->company_id, $request,
            ['default_ends_at' => $default?->toIso8601String(), 'selected_ends_at' => $selected->toIso8601String()], $operation);
    }

    public function paymentRejected(User $actor, int $orderId, string $operation, Request $request, string $reason, array $details = []): void
    {
        DB::transaction(function () use ($actor, $orderId, $operation, $request, $reason, $details): void {
            $this->requirePaymentAdmin($actor, $operation);
            if (! in_array($reason, ['scope_missing', 'paid_without_grant', 'ineligible', 'amount_mismatch', 'bank_missing', 'zero_reason', 'overlap', 'duplicate_reference', 'renewal_missing'], true)) {
                throw new InvalidArgumentException('Invalid payment rejection reason.');
            }
            $order = Order::query()->whereKey($orderId)->firstOrFail(['id', 'company_id', 'job_id']);
            $metadata = ['order_id' => $orderId, 'company_id' => $order->company_id, 'job_id' => $order->job_id];
            if ($reason === 'amount_mismatch') {
                if (array_keys($details) !== ['order_amount', 'received_amount'] || ! is_int($details['order_amount']) || ! is_int($details['received_amount'])) {
                    throw new InvalidArgumentException('Invalid amount rejection audit.');
                }
                $metadata = ['order_id' => $orderId] + $details;
            }
            $this->appendPaymentOnce($actor, 'order.confirm_rejected', 'order', (string) $orderId, $order->company_id, $request, $metadata, $operation, $reason);
        });
    }

    public function grantRevoked(User $actor, AccessGrant $grant, string $reason, string $operation, Request $request): void
    {
        $this->requirePaymentAdmin($actor, $operation);
        if ($grant->status !== 'revoked' || $grant->revoked_by !== $actor->id || ! in_array($reason, AccessGrantLifecycle::REASONS, true)) {
            throw new InvalidArgumentException('Invalid grant revocation audit.');
        }
        $this->appendPaymentOnce($actor, 'access_grant.revoked', 'access_grant', (string) $grant->id, $grant->company_id, $request,
            ['grant_id' => $grant->id, 'job_id' => $grant->job_id, 'order_id' => $grant->order_id, 'reason' => $reason], $operation.':'.$grant->id);
    }

    private function requirePaymentAdmin(User $actor, string $operation): void
    {
        abort_unless(User::query()->whereKey($actor->id)->where('role', Role::Admin->value)->where('status', UserStatus::Active->value)->exists(), 403);
        if (! Str::isUlid($operation)) {
            throw new InvalidArgumentException('Invalid payment audit operation.');
        }
    }

    private function appendPaymentOnce(User $actor, string $action, string $type, string $id, int $companyId, Request $request, array $metadata, string $operation, ?string $reason = null): void
    {
        $key = $action.':'.$operation;
        $previous = DB::table('audit_logs')->where('operation_key', $key)->first(['actor_id', 'subject_type', 'subject_id']);
        if ($previous) {
            if ($previous->actor_id !== $actor->id || $previous->subject_type !== $type || $previous->subject_id !== $id) {
                throw new InvalidArgumentException('Conflicting payment audit operation.');
            }

            return;
        }
        $this->append($actor, $action, $type, $id, $companyId, $request, $metadata, $key, $reason);
    }

    private function append(User $actor, string $action, string $type, string $id, ?int $companyId, Request $request, array $metadata, ?string $operation = null, ?string $reason = null): void
    {
        $current = User::query()->whereKey($actor->id)->where('status', UserStatus::Active->value)->firstOrFail(['id', 'role']);
        $row = [
            'actor_id' => $current->id, 'actor_role' => $current->role->value, 'action' => $action,
            'subject_type' => $type, 'subject_id' => $id, 'company_id' => $companyId, 'operation_key' => $operation,
            'ip' => $request->ip(),
            // Raw user agents can contain attacker supplied contact data: retain only a coarse family.
            'user_agent' => preg_match('/Firefox|Edg|Chrome|Safari/', $request->userAgent() ?? '', $match) ? $match[0] : 'Other',
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ];
        if ($reason !== null) {
            $row['reason_code'] = $reason;
        }
        DB::table('audit_logs')->insert($row);
    }
}

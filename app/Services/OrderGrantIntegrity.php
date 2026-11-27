<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AccessGrant;
use App\Models\Order;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Data invariants for ordinary model saves; no HTTP/payment/grant issuance workflow. */
class OrderGrantIntegrity
{
    public function saveOrder(Order $order, Closure $persist): bool
    {
        return DB::transaction(function () use ($order, $persist): bool {
            $previous = $order->exists ? DB::table('orders')->where('id', $order->getKey())->lockForUpdate()->first() : null;
            $this->mergeCurrent($order, $previous);
            if ($previous && $previous->job_id !== null) {
                foreach (['company_id', 'job_id', 'type', 'amount', 'method', 'payment_reference', 'payment_instructions'] as $field) {
                    if ($order->isDirty($field)) {
                        $this->invalid($field, 'immutable_order');
                    }
                }
            }
            Validator::make($order->getAttributes(), [
                'company_id' => ['required', 'integer'], 'type' => ['required', Rule::in(Order::TYPES)],
                'amount' => ['required', 'integer', 'min:0'], 'status' => ['required', Rule::in(Order::STATUSES)],
                'method' => ['required', Rule::in(Order::METHODS)], 'reason' => ['nullable', 'string', 'max:2000'],
                'confirmed_by' => ['nullable', 'integer'], 'confirmed_at' => ['nullable', 'date'],
                'transaction_reference' => ['nullable', 'string', 'max:255'],
            ])->validate();
            if (($order->amount === 0 && (! in_array($order->method, ['free_trial', 'gift'], true) || trim($order->reason ?? '') === ''))
                || ($order->amount > 0 && $order->method !== 'bank_transfer')) {
                $this->invalid('reason', 'zero_reason');
            }
            if ($order->status === 'paid') {
                if (! $order->confirmed_at || ! $order->confirmed_by) {
                    $this->invalid('confirmed_by', 'admin_confirmation');
                }
                if (! $previous || $previous->status !== 'paid' || $previous->confirmed_by !== $order->confirmed_by) {
                    $this->requireAdmin((int) $order->confirmed_by, 'confirmed_by');
                }
            }
            if ($previous && DB::table('access_grants')->where('order_id', $order->id)->where('status', '<>', 'revoked')->exists()
                && ($order->status !== 'paid' || $order->type !== 'access_grant' || $order->company_id !== $previous->company_id)) {
                $this->invalid('status', 'grant_attached');
            }

            return (bool) $persist();
        });
    }

    public function saveGrant(AccessGrant $grant, Closure $persist): bool
    {
        return DB::transaction(function () use ($grant, $persist): bool {
            // Binding is immutable. Lock order first in both write paths to serialize changes.
            $current = $grant->exists ? DB::table('access_grants')->where('id', $grant->getKey())->first() : null;
            $this->mergeCurrent($grant, $current);
            Validator::make($grant->getAttributes(), [
                'company_id' => ['required', 'integer'], 'job_id' => ['required', 'integer'], 'order_id' => ['required', 'integer'],
                'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
                'status' => ['required', Rule::in(['active', 'revoked'])], 'created_by' => ['required', 'integer'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ])->validate();
            $order = Order::whereKey($grant->order_id)->lockForUpdate()->first();
            if (! $order || $order->company_id !== $grant->company_id || $order->type !== 'access_grant' || $order->status !== 'paid'
                || ($order->job_id !== null && $order->job_id !== $grant->job_id)) {
                $this->invalid('order_id', 'paid_order');
            }
            if ($current) {
                $latest = DB::table('access_grants')->where('id', $grant->id)->lockForUpdate()->first();
                foreach (['order_id', 'company_id', 'job_id', 'created_by'] as $field) {
                    if ($grant->$field !== $latest->$field) {
                        $this->invalid($field, 'immutable_binding');
                    }
                }
                // Refresh non-dirty fields after acquiring the row lock as well.
                $this->mergeCurrent($grant, $latest);
            } else {
                $this->requireAdmin((int) $grant->created_by, 'created_by');
            }

            return (bool) $persist();
        });
    }

    private function mergeCurrent(Model $model, ?object $current): void
    {
        if ($current) {
            $dirty = $model->getDirty();
            $model->setRawAttributes((array) $current, true);
            $model->forceFill($dirty);
        }
    }

    private function requireAdmin(int $id, string $field): void
    {
        if (! User::whereKey($id)->where('role', Role::Admin->value)->where('status', UserStatus::Active->value)->sharedLock()->first(['id'])) {
            $this->invalid($field, 'admin_confirmation');
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __('orders.'.$message)]);
    }
}

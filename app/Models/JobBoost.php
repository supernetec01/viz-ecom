<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
class JobBoost extends Model
{
    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'job_id' => 'integer', 'order_id' => 'integer', 'plan_id' => 'integer', 'priority' => 'integer',
            'created_by' => 'integer', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    public function save(array $options = []): bool
    {
        Validator::make($this->getAttributes(), ['starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'priority' => ['required', 'integer', 'between:1,1000000']])->validate();

        return DB::transaction(function () use ($options): bool {
            $order = Order::query()->whereKey($this->order_id)->lockForUpdate()->first();
            if ($this->exists || ! User::query()->whereKey($this->created_by)->where('role', 'admin')->where('status', 'active')->sharedLock()->exists() || ! $order || $order->status !== 'paid' || $order->type !== 'job_boost'
                || $order->company_id !== $this->company_id || $order->job_id !== $this->job_id || $order->boost_plan_id !== $this->plan_id
                || $order->boost_priority !== $this->priority || $order->confirmed_by !== $this->created_by
                || ! $this->starts_at->equalTo($order->confirmed_at)
                || ! $this->ends_at->equalTo($this->starts_at->addDays($order->boost_days))) {
                throw ValidationException::withMessages(['order_id' => __('boosts.invalid_binding')]);
            }

            return parent::save($options);
        });
    }

    /** @param Builder<static> $query @return Builder<static> */
    public function scopeEffective(Builder $query): Builder
    {
        $at = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');

        return $query->where('starts_at', '<=', $at)->where('ends_at', '>', $at)
            ->whereHas('order', fn (Builder $order) => $order->where('type', 'job_boost')->where('status', 'paid')
                ->whereColumn('orders.company_id', 'job_boosts.company_id')->whereColumn('orders.job_id', 'job_boosts.job_id'));
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }
}

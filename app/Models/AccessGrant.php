<?php

namespace App\Models;

use App\Services\OrderGrantIntegrity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
class AccessGrant extends Model
{
    protected $guarded = ['*'];

    protected $attributes = ['status' => 'active'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'job_id' => 'integer', 'order_id' => 'integer', 'created_by' => 'integer', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    public function save(array $options = [])
    {
        return app(OrderGrantIntegrity::class)->saveGrant($this, fn () => parent::save($options));
    }

    /** Temporal/data integrity only; T30 must authorize actor, company and application separately. */
    public function isEffective(): bool
    {
        return $this->exists && static::query()->whereKey($this->getKey())->effective()->exists();
    }

    /** @param Builder<static> $query @return Builder<static> */
    public function scopeEffective(Builder $query): Builder
    {
        $at = now()->format('Y-m-d H:i:s.uP');

        return $query->where('access_grants.status', 'active')->where('starts_at', '<=', $at)->where('ends_at', '>', $at)
            ->whereHas('order', fn (Builder $order) => $order->where('status', 'paid')->where('type', 'access_grant')
                ->whereColumn('orders.company_id', 'access_grants.company_id')
                ->where(fn (Builder $scope) => $scope->whereNull('orders.job_id')->orWhereColumn('orders.job_id', 'access_grants.job_id')));
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

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

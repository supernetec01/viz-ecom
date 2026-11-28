<?php

namespace App\Models;

use App\Services\OrderGrantIntegrity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @property array<array-key, mixed>|null $payment_instructions JSONB decoded by the array cast. */
class Order extends Model
{
    public const TYPES = ['access_grant', 'job_boost'];

    public const STATUSES = ['pending', 'paid', 'cancelled'];

    public const METHODS = ['bank_transfer', 'free_trial', 'gift'];

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = ['status' => 'pending', 'method' => 'bank_transfer'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'company_id' => 'integer', 'job_id' => 'integer', 'duration_days' => 'integer', 'duration_max_days' => 'integer', 'payment_instructions' => 'array', 'confirmed_by' => 'integer', 'confirmed_at' => 'immutable_datetime'];
    }

    public function save(array $options = [])
    {
        return app(OrderGrantIntegrity::class)->saveOrder($this, fn () => parent::save($options));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** @return HasOne<AccessGrant, $this> */
    public function accessGrant(): HasOne
    {
        return $this->hasOne(AccessGrant::class);
    }
}

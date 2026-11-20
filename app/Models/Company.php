<?php

namespace App\Models;

use App\Enums\CompanyType;
use App\Enums\Role;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property CompanyType $type */
#[Fillable(['name', 'type', 'tax_code', 'address', 'logo_path', 'introduction', 'industry', 'products'])]
class Company extends Model
{
    use SoftDeletes;

    protected $attributes = ['type' => 'direct'];

    protected function casts(): array
    {
        return ['type' => CompanyType::class, 'verified_at' => 'datetime'];
    }

    /** @return HasMany<CompanyChannel, $this> */
    public function channels(): HasMany
    {
        return $this->hasMany(CompanyChannel::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null && $this->verified_by !== null;
    }

    /** @return BelongsToMany<User, $this> */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'staff_company');
    }

    /** @return BelongsToMany<User, $this> */
    public function employers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $actor = $user->fresh();
        if (! $actor || $actor->status !== UserStatus::Active) {
            return $query->whereRaw('1 = 0');
        }

        return match ($actor->role) {
            Role::Admin => $query,
            Role::Staff => $query->whereHas('staff', fn (Builder $users) => $users->where('users.id', $actor->id)),
            Role::Employer => $query->whereHas('employers', fn (Builder $users) => $users->where('users.id', $actor->id)),
        };
    }
}

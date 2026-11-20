<?php

namespace App\Models;

use App\Enums\CompanyType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
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
}

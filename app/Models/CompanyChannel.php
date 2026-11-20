<?php

namespace App\Models;

use App\Enums\ChannelType;
use App\Rules\AllowedChannelUrl;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** @property ChannelType $type */
#[Fillable(['company_id', 'type', 'display_name', 'url'])]
class CompanyChannel extends Model
{
    protected function casts(): array
    {
        return ['type' => ChannelType::class];
    }

    protected static function booted(): void
    {
        static::saving(function (CompanyChannel $channel): void {
            Validator::make($channel->getAttributes(), [
                'type' => ['required', Rule::enum(ChannelType::class)],
                'display_name' => ['required', 'string', 'max:255'],
                'url' => ['required', 'url:http,https', 'max:2048', new AllowedChannelUrl($channel->type->value)],
            ])->validate();
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

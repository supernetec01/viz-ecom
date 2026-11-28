<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class Plan extends Model
{
    protected $fillable = ['name', 'duration_days', 'priority', 'price', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['duration_days' => 'integer', 'priority' => 'integer', 'price' => 'integer', 'is_active' => 'boolean'];
    }

    public function save(array $options = []): bool
    {
        $integer = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value) && ! is_string($value)) {
                $fail(__('payments.invalid'));
            }
        };
        Validator::make($this->getAttributes(), ['name' => ['required', 'string', 'max:255'], 'duration_days' => ['required', 'integer', $integer, 'between:1,366'],
            'priority' => ['required', 'integer', $integer, 'between:1,1000000'], 'price' => ['required', 'integer', $integer, 'min:1'], 'is_active' => ['boolean']])->validate();

        return parent::save($options);
    }
}

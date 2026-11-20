<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AllowedChannelUrl implements ValidationRule
{
    public function __construct(private string $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) ? parse_url($value) : false;
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        $domains = config('company-channels.'.$this->type, []);
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || $host === ''
            || ! array_any($domains, fn (string $domain): bool => $host === strtolower($domain) || str_ends_with($host, '.'.strtolower($domain)))) {
            $fail(__('ui.invalid_channel_url'));
        }
    }
}

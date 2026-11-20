<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

class VietnamTime
{
    public static function format(DateTimeInterface|string $value, string $format = 'd/m/Y H:i'): string
    {
        return CarbonImmutable::parse($value, 'UTC')->setTimezone(config('app.display_timezone'))->format($format);
    }
}

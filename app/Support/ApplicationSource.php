<?php

namespace App\Support;

use Illuminate\Contracts\Session\Session;

class ApplicationSource
{
    public const SESSION_KEY = 'application_source';

    public static function normalize(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/D', $value) === 1 ? strtolower($value) : null;
    }

    public static function remember(Session $session, mixed $value): void
    {
        if (($source = self::normalize($value)) !== null) {
            $session->put(self::SESSION_KEY, $source);
        }
    }

    public static function current(Session $session): ?string
    {
        return self::normalize($session->get(self::SESSION_KEY));
    }
}

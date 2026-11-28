<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class PaymentTransactionIdentity
{
    public static function normalize(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw ValidationException::withMessages(['transaction_reference' => __('payments.invalid_reference')]);
        }

        return preg_replace('/[\s\p{Z}\x{200B}\x{FEFF}]+/u', '', mb_strtoupper($value, 'UTF-8')) ?? '';
    }

    public static function bankKey(array $instructions): string
    {
        foreach (['bank', 'account'] as $field) {
            if (! isset($instructions[$field]) || ! is_string($instructions[$field]) || self::normalize($instructions[$field]) === '') {
                throw ValidationException::withMessages(['transaction_reference' => __('payments.bank_missing')]);
            }
        }

        return hash('sha256', json_encode([self::normalize($instructions['bank']), self::normalize($instructions['account'])], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}

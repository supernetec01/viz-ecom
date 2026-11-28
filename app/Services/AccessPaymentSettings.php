<?php

namespace App\Services;

use App\Exceptions\AccessPaymentUnavailable;
use App\Support\GrantDuration;

class AccessPaymentSettings
{
    /** @return array{amount: int, duration_days: int, instructions: array{bank: string, account: string, holder: string}} */
    public function snapshot(): array
    {
        $configured = config('access-payments.amount_vnd');
        if (! is_int($configured) && ! is_string($configured)) {
            throw new AccessPaymentUnavailable;
        }
        $amount = filter_var($configured, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($amount === false) {
            throw new AccessPaymentUnavailable;
        }
        $instructions = $this->bankInstructions();

        $duration = filter_var(config('access-payments.duration_days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => app(GrantDuration::class)->bounds()['max']]]);
        if ($duration === false || is_bool(config('access-payments.duration_days'))) {
            throw new AccessPaymentUnavailable;
        }

        return ['amount' => $amount, 'duration_days' => $duration, 'instructions' => $instructions];
    }

    /** @return array{bank: string, account: string, holder: string} */
    public function bankInstructions(): array
    {
        $instructions = [];
        foreach (['bank' => 'bank_name', 'account' => 'account_number', 'holder' => 'account_holder'] as $key => $setting) {
            $value = config('access-payments.'.$setting);
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
                throw new AccessPaymentUnavailable;
            }
            $instructions[$key] = trim($value);
        }

        return $instructions;
    }
}

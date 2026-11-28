<?php

return [
    'amount_vnd' => env('ACCESS_PRICE_VND'),
    'bank_name' => env('ACCESS_BANK_NAME'),
    'account_number' => env('ACCESS_BANK_ACCOUNT'),
    'account_holder' => env('ACCESS_BANK_HOLDER'),
    'duration_days' => env('ACCESS_DURATION_DAYS'),
    'min_days' => env('ACCESS_MIN_DAYS', 1),
    'max_days' => env('ACCESS_MAX_DAYS', 366),
];

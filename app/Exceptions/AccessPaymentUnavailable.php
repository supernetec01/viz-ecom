<?php

namespace App\Exceptions;

use RuntimeException;

class AccessPaymentUnavailable extends RuntimeException
{
    public function __construct(public readonly bool $legacyOrder = false)
    {
        parent::__construct('Access payment unavailable.');
    }
}

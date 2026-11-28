<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentConfirmationRejected extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly array $details = [])
    {
        parent::__construct('Payment confirmation rejected.');
    }
}

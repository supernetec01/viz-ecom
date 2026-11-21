<?php

namespace App\Exceptions;

use RuntimeException;

class PdfInspectionFailed extends RuntimeException
{
    public function __construct(public readonly string $reason = 'unavailable')
    {
        parent::__construct('PDF inspection failed: '.$reason);
    }
}

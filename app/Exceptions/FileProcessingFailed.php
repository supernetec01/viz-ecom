<?php

namespace App\Exceptions;

use RuntimeException;

final class FileProcessingFailed extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $transient = false)
    {
        parent::__construct('File processing failed: '.$reason);
    }
}

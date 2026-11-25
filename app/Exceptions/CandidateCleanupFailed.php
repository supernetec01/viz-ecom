<?php

namespace App\Exceptions;

use RuntimeException;

final class CandidateCleanupFailed extends RuntimeException
{
    public function __construct(public readonly array $report)
    {
        parent::__construct('Candidate cleanup failed.');
    }
}

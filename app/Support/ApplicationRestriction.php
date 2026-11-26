<?php

namespace App\Support;

final class ApplicationRestriction
{
    // T43 will read violation flags here; ordinary soft deletes remain in policy.
    public function blocked(int $companyId, int $jobId): bool
    {
        return false;
    }
}

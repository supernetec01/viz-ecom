<?php

namespace App\Policies;

use App\Models\Job;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationPolicy
{
    // Guest submission only. Reading candidate records remains denied until T30.
    public function assertMaySubmit(Job $job, ?string $phone = null): void
    {
        abort_unless($job->canReceiveApplications(), 403);
        if ($phone !== null && DB::table('applications')->where('job_id', $job->id)
            ->where('phone_normalized', $phone)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['phone' => __('applications.duplicate')]);
        }
    }
}

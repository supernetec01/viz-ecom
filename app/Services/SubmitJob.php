<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Models\Job;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SubmitJob
{
    public function submit(User $actor, Job $job): Job
    {
        return DB::transaction(function () use ($actor, $job): Job {
            $job = Job::whereKey($job->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('submit', $job);
            // Job's save hook screens the latest stored content atomically before publication.
            $job->transitionTo(JobStatus::Published);

            return $job;
        });
    }
}

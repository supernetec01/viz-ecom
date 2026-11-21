<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Models\Job;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class SubmitJob
{
    public function submit(User $actor, Job $job, array $input = []): Job
    {
        return DB::transaction(function () use ($actor, $job, $input): Job {
            $job = Job::whereKey($job->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('submit', $job);
            if (array_key_exists('expires_at', $input)) {
                $data = Validator::make($input, ['expires_at' => ['required', 'date', 'after:now']], [], ['expires_at' => __('ui.jobs.expires_at')])->validate();
                $job->expires_at = $data['expires_at'];
            }
            // Job's save hook screens the latest stored content atomically before publication.
            $job->transitionTo(JobStatus::Published);

            return $job;
        });
    }
}

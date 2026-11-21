<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Models\Company;
use App\Models\Job;
use App\Models\User;
use App\Support\JobData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageJobs
{
    public function create(User $actor, array $input): Job
    {
        return DB::transaction(function () use ($actor, $input): Job {
            Gate::forUser($actor)->authorize('create', Job::class);
            $data = JobData::validate($input);
            $company = Company::findOrFail($data['company_id']);
            Gate::forUser($actor)->authorize('update', $company);
            $job = new Job($data);
            $job->created_by = $actor->id;
            $job->save();

            return $job;
        });
    }

    public function update(User $actor, Job $job, array $input): Job
    {
        return DB::transaction(function () use ($actor, $job, $input): Job {
            $job = Job::whereKey($job->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $job);
            if (isset($input['company_id']) && (int) $input['company_id'] !== $job->company_id) {
                throw ValidationException::withMessages(['company_id' => __('ui.jobs.fixed_company')]);
            }
            $job->fill(JobData::validate($input + ['company_id' => $job->company_id]));
            $job->save();

            return $job;
        });
    }

    public function duplicate(User $actor, Job $job): Job
    {
        return DB::transaction(function () use ($actor, $job): Job {
            $job = Job::whereKey($job->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('replicate', $job);
            // Copy only form content. Never replicate dates, state, slug or relations (including future boosts).
            $data = array_intersect_key($job->getAttributes(), JobData::rules());
            $data['expires_at'] = null;

            return $this->create($actor, $data);
        });
    }

    public function changeState(User $actor, Job $job, string $action): Job
    {
        Validator::make(['action' => $action], ['action' => 'required|in:pause,resume,close,redraft'])->validate();

        return DB::transaction(function () use ($actor, $job, $action): Job {
            $job = Job::whereKey($job->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize($action, $job);
            $next = match ($action) {
                'pause' => JobStatus::Paused,
                'close' => JobStatus::Closed,
                'redraft' => JobStatus::Draft,
                'resume' => $job->expires_at && $job->expires_at->isFuture() ? JobStatus::Published : JobStatus::Expired,
                default => throw ValidationException::withMessages(['action' => __('validation.in', ['attribute' => 'action'])]),
            };
            $job->transitionTo($next);

            return $job;
        });
    }
}

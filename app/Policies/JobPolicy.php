<?php

namespace App\Policies;

use App\Enums\JobStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Job;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class JobPolicy
{
    public function viewAny(User $user): bool
    {
        $actor = $user->fresh();

        return $actor && $actor->status === UserStatus::Active && $actor->role !== Role::Employer;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Job $job): Response
    {
        return $this->decision(Job::visibleTo($user)->whereKey($job->id)->exists());
    }

    public function update(User $user, Job $job): Response
    {
        return $this->decision($this->view($user, $job)->allowed() && $job->fresh()?->status === JobStatus::Draft);
    }

    public function replicate(User $user, Job $job): Response
    {
        return $this->view($user, $job);
    }

    public function submit(User $user, Job $job): Response
    {
        return $this->update($user, $job);
    }

    public function reviewAny(User $user): Response
    {
        $actor = $user->fresh();

        return $this->decision($actor && $actor->status === UserStatus::Active && $actor->role === Role::Admin);
    }

    public function review(User $user, Job $job): Response
    {
        return $this->decision($this->reviewAny($user)->allowed() && $job->fresh()?->status === JobStatus::Pending);
    }

    public function pause(User $user, Job $job): Response
    {
        return $this->inStates($user, $job, [JobStatus::Published]);
    }

    public function resume(User $user, Job $job): Response
    {
        return $this->inStates($user, $job, [JobStatus::Paused]);
    }

    public function close(User $user, Job $job): Response
    {
        return $this->inStates($user, $job, [JobStatus::Published, JobStatus::Paused]);
    }

    public function redraft(User $user, Job $job): Response
    {
        return $this->inStates($user, $job, [JobStatus::Pending, JobStatus::Paused, JobStatus::Expired]);
    }

    private function inStates(User $user, Job $job, array $states): Response
    {
        return $this->decision($this->view($user, $job)->allowed() && in_array($job->fresh()?->status, $states, true));
    }

    private function decision(bool $allowed): Response
    {
        return $allowed ? Response::allow() : Response::denyWithStatus(403);
    }
}

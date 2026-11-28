<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Plan;
use App\Models\User;

class PlanPolicy
{
    public function viewAny(User $user): bool
    {
        $actor = $user->fresh();

        return $actor && $actor->status === UserStatus::Active && $actor->role !== Role::Employer;
    }

    public function create(User $user): bool
    {
        return app(OrderPolicy::class)->viewAny($user);
    }

    public function update(User $user, Plan $plan): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Plan $plan): bool
    {
        return false;
    }
}

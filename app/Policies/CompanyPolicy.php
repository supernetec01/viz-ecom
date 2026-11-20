<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        $actor = $user->fresh();

        return $actor && $actor->status === UserStatus::Active && $actor->role !== Role::Employer;
    }

    public function view(User $user, Company $company): bool
    {
        return Company::visibleTo($user)->whereKey($company->id)->exists();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Company $company): bool
    {
        return $this->viewAny($user) && $this->view($user, $company);
    }

    public function verify(User $user, Company $company): bool
    {
        $actor = $user->fresh();

        return $actor && $actor->status === UserStatus::Active && $actor->role === Role::Admin;
    }
}

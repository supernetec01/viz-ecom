<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;

class OrderPolicy
{
    public function viewAny(User $actor): bool
    {
        return User::query()->whereKey($actor->id)->where('role', Role::Admin->value)->where('status', UserStatus::Active->value)->exists();
    }
}

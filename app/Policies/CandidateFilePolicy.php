<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\CandidateFile;
use App\Models\User;

class CandidateFilePolicy
{
    public function viewAny(User $user): bool
    {
        $actor = $user->fresh();

        return $actor !== null && $actor->role === Role::Admin && $actor->status === UserStatus::Active;
    }

    public function view(User $user, CandidateFile $file): bool
    {
        return $this->viewAny($user);
    }

    public function retry(User $user, CandidateFile $file): bool
    {
        $current = $file->fresh();

        return $this->viewAny($user) && $current !== null && $current->scan_status !== 'infected'
            && $current->review_required && ($current->scan_status === 'error' || ($current->scan_status === 'clean' && $current->preview_status === 'failed'));
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CandidateFile $file): bool
    {
        return false;
    }

    public function delete(User $user, CandidateFile $file): bool
    {
        return false;
    }
}

<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;
use App\Notifications\EmployerInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EmployerAccounts
{
    public function create(User $actor, Company $company, array $input): User
    {
        Gate::forUser($actor)->authorize('update', $company);
        if (is_string($input['email'] ?? null)) {
            $input['email'] = Str::lower(trim($input['email']));
        }
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ])->validate();

        return DB::transaction(function () use ($company, $data): User {
            $user = new User;
            $user->forceFill($data + ['password' => Str::random(64), 'role' => Role::Employer, 'status' => UserStatus::Active])->save();
            $company->employers()->attach($user);
            $this->sendInvitation($company, $user);

            return $user;
        });
    }

    public function resend(User $actor, Company $company, User $employer): void
    {
        $employer = $this->managedEmployer($actor, $company, $employer);
        abort_unless($employer->status === UserStatus::Active, 403);
        $this->sendInvitation($company, $employer);
    }

    public function setStatus(User $actor, Company $company, User $employer, UserStatus $status): void
    {
        $employer = $this->managedEmployer($actor, $company, $employer);
        DB::transaction(function () use ($employer, $status): void {
            $employer->forceFill(['status' => $status])->save();
            Password::deleteToken($employer);
        });
    }

    private function managedEmployer(User $actor, Company $company, User $employer): User
    {
        Gate::forUser($actor)->authorize('update', $company);
        $employer = $employer->fresh();
        abort_unless($employer instanceof User && $employer->role === Role::Employer && $company->employers()->whereKey($employer->id)->exists(), 403);
        // A shared account must not be changed by staff who cannot manage all linked companies.
        abort_if($employer->companies()->whereNotIn('companies.id', Company::visibleTo($actor)->select('companies.id'))->exists(), 403);

        return $employer;
    }

    private function sendInvitation(Company $company, User $employer): void
    {
        $token = Password::createToken($employer);
        DB::afterCommit(fn () => $employer->notify(new EmployerInvitation($token, $company->name)));
    }
}

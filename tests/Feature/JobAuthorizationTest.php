<?php

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('returns 403 when staff tries to manage another company job', function (string $ability) {
    $owner = User::factory()->create(['role' => Role::Staff]);
    $other = User::factory()->create(['role' => Role::Staff]);
    $job = managedJob($owner);
    expect(Gate::forUser($other)->inspect($ability, $job)->status())->toBe(403);
})->with(['view', 'update', 'replicate', 'pause', 'resume', 'close', 'redraft']);

it('denies employers and locked users job management even when linked to the company', function (string $ability) {
    $staff = User::factory()->create(['role' => Role::Staff]);
    $job = managedJob($staff);
    $employer = User::factory()->create();
    $job->company->employers()->attach($employer);
    expect(Gate::forUser($employer)->inspect($ability, $job)->status())->toBe(403);
    $staff->forceFill(['status' => UserStatus::Locked])->save();
    expect(Gate::forUser($staff)->inspect($ability, $job)->status())->toBe(403);
})->with(['view', 'update', 'replicate', 'pause', 'resume', 'close', 'redraft']);

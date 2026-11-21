<?php

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Job;
use App\Services\ReviewJob;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('denies review queue and decisions to staff and employers with 403', function (Role $role) {
    $actor = jobManager($role);
    $job = heldReviewJob($actor);
    expect(Gate::forUser($actor)->inspect('reviewAny', Job::class)->status())->toBe(403)
        ->and(Gate::forUser($actor)->inspect('review', $job)->status())->toBe(403);
    $this->actingAs($actor)->get('/app/duyet-tin')->assertForbidden();
})->with([Role::Staff, Role::Employer]);

it('rechecks a revoked admin role or locked account for every review decision', function (string $change) {
    $admin = jobManager(Role::Admin);
    $job = heldReviewJob(jobManager());
    $stale = $admin->fresh();
    $admin->forceFill($change === 'role' ? ['role' => Role::Staff] : ['status' => UserStatus::Locked])->save();
    expect(Gate::forUser($stale)->inspect('reviewAny', Job::class)->status())->toBe(403)
        ->and(fn () => app(ReviewJob::class)->approve($stale, $job, ['expires_at' => now()->addDays(7)]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReviewJob::class)->reject($stale, $job, ['reason' => 'Không phù hợp']))->toThrow(AuthorizationException::class);
})->with(['role', 'locked']);

it('denies direct review service calls from assigned staff and employers', function (Role $role) {
    $actor = jobManager($role);
    $job = heldReviewJob($actor);
    expect(fn () => app(ReviewJob::class)->approve($actor, $job, ['expires_at' => now()->addDays(7)]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReviewJob::class)->reject($actor, $job, ['reason' => 'Không phù hợp']))->toThrow(AuthorizationException::class);
})->with([Role::Staff, Role::Employer]);

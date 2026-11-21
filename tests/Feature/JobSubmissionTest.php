<?php

use App\Enums\JobStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\ManageJobs;
use App\Services\SubmitJob;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('denies submission of another company job with 403', function () {
    $job = managedJob(jobManager());
    expect(Gate::forUser(jobManager())->inspect('submit', $job)->status())->toBe(403);
});

it('denies submission by employers locked staff and revoked assignments with 403', function (string $reason) {
    $staff = jobManager();
    $job = managedJob($staff);
    if ($reason === 'employer') {
        $staff = User::factory()->create(['role' => Role::Employer]);
        $job->company->employers()->attach($staff);
    } elseif ($reason === 'locked') {
        $staff->forceFill(['status' => UserStatus::Locked])->save();
    } else {
        $job->company->staff()->detach($staff);
    }
    expect(Gate::forUser($staff)->inspect('submit', $job)->status())->toBe(403);
})->with(['employer', 'locked', 'revoked']);

it('publishes clean current content for assigned staff and admins', function (Role $role) {
    $actor = jobManager($role);
    $job = managedJob($actor);
    $job->update(['expires_at' => now()->addDays(7)]);
    $result = app(SubmitJob::class)->submit($actor, $job);
    expect($result->status)->toBe(JobStatus::Published)->and($result->content_scan_reasons)->toBe([])
        ->and($result->content_scanned_at)->not->toBeNull()->and($result->published_at)->not->toBeNull();
})->with([Role::Staff, Role::Admin]);

it('holds contact content in each job field with no publication date', function (string $field) {
    $actor = jobManager();
    $job = managedJob($actor);
    $job->update([$field => 'Liên hệ 0901234567']);
    $result = app(SubmitJob::class)->submit($actor, $job);
    expect($result->fresh()->status)->toBe(JobStatus::Pending)->and($result->published_at)->toBeNull()
        ->and($result->content_scan_reasons)->toContain(['field' => $field, 'rule' => 'phone']);
    expect($result->toJson())->not->toContain('content_scan_reasons', 'content_scanned_at');
})->with(['title', 'description', 'requirements', 'benefits']);

it('persists the exact outside href for T13 while ignoring the internal display link', function () {
    config(['app.url' => 'https://jobs.example.test']);
    $actor = jobManager();
    $job = managedJob($actor);
    $job->update(['description' => '<a href="https://outside.test/apply">https://jobs.example.test</a>']);
    $result = app(SubmitJob::class)->submit($actor, $job);
    expect($result->status)->toBe(JobStatus::Pending)
        ->and($result->fresh()->content_scan_reasons)->toEqual([['field' => 'description', 'rule' => 'external_link', 'value' => 'https://outside.test/apply']]);
});

it('does not screen separate company channels or introduction', function () {
    $actor = jobManager();
    $job = managedJob($actor);
    $job->company->update(['introduction' => 'Zalo 0901234567 https://company.example']);
    $job->company->channels()->create(['type' => 'website', 'display_name' => 'Website', 'url' => 'https://company.example']);
    $job->update(['expires_at' => now()->addDays(7)]);
    expect(app(SubmitJob::class)->submit($actor, $job)->status)->toBe(JobStatus::Published);
});

it('screens at the model boundary so a direct publish attempt cannot bypass a hold', function () {
    $job = managedJob(jobManager());
    $job->update(['benefits' => 'Inbox để nhận thông tin']);
    $job->transitionTo(JobStatus::Published);
    expect($job->fresh()->status)->toBe(JobStatus::Pending)
        ->and($job->content_scan_reasons)->toContain(['field' => 'benefits', 'rule' => 'inbox']);
});

it('reloads stored content rather than trusting a stale clean model', function () {
    $actor = jobManager();
    $job = managedJob($actor);
    $changed = $job->fresh();
    $changed->update(['description' => 'Zalo để ứng tuyển']);
    expect(app(SubmitJob::class)->submit($actor, $job)->status)->toBe(JobStatus::Pending);
});

it('rolls back scan metadata when clean publication has no valid expiry', function () {
    $actor = jobManager();
    $job = managedJob($actor);
    expect(fn () => app(SubmitJob::class)->submit($actor, $job))->toThrow(ValidationException::class);
    $stored = $job->fresh();
    expect($stored->status)->toBe(JobStatus::Draft)->and($stored->content_scanned_at)->toBeNull()->and($stored->content_scan_reasons)->toBe([]);
});

it('clears old findings on redraft and screens the revised content again', function () {
    $actor = jobManager();
    $job = managedJob($actor);
    $job->update(['description' => 'Zalo để ứng tuyển']);
    $held = app(SubmitJob::class)->submit($actor, $job);
    $draft = app(ManageJobs::class)->changeState($actor, $held, 'redraft');
    expect($draft->content_scan_reasons)->toBe([])->and($draft->content_scanned_at)->toBeNull();
    $draft->update(['description' => '<p>Ứng tuyển trên website</p>', 'expires_at' => now()->addDays(7)]);
    expect(app(SubmitJob::class)->submit($actor, $draft)->status)->toBe(JobStatus::Published);
});

it('cannot submit a held or published job a second time', function () {
    $actor = jobManager();
    $job = managedJob($actor);
    $job->update(['description' => 'Inbox']);
    $held = app(SubmitJob::class)->submit($actor, $job);
    expect(fn () => app(SubmitJob::class)->submit($actor, $held))->toThrow(AuthorizationException::class);
    $published = managedJob($actor);
    $published->update(['expires_at' => now()->addDays(7)]);
    $published = app(SubmitJob::class)->submit($actor, $published);
    expect(fn () => app(SubmitJob::class)->submit($actor, $published))->toThrow(AuthorizationException::class);
});

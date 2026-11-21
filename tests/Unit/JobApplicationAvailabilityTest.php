<?php

use App\Enums\JobStatus;
use App\Models\Job;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);
beforeEach(fn () => CarbonImmutable::setTestNow('2026-11-21 00:00:00 UTC'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('accepts applications only for published jobs among all approved states', function (JobStatus $status, bool $allowed) {
    $job = (new Job)->forceFill(['status' => $status, 'published_at' => now()->subDay(), 'expires_at' => now()->addDay()]);
    expect($job->canReceiveApplications())->toBe($allowed);
})->with([
    [JobStatus::Draft, false], [JobStatus::Pending, false], [JobStatus::Published, true],
    [JobStatus::Paused, false], [JobStatus::Expired, false], [JobStatus::Closed, false],
]);

it('stops receiving exactly at the expiry boundary', function (int $seconds, bool $allowed) {
    $job = (new Job)->forceFill(['status' => JobStatus::Published, 'published_at' => now()->subDay(), 'expires_at' => now()->addSeconds($seconds)]);
    expect($job->canReceiveApplications())->toBe($allowed);
})->with([[-1, false], [0, false], [1, true]]);

it('does not receive applications for missing dates scheduled publication or a deleted job', function (array $override) {
    $job = (new Job)->forceFill(array_replace(['status' => JobStatus::Published, 'published_at' => now()->subDay(), 'expires_at' => now()->addDay()], $override));
    expect($job->canReceiveApplications())->toBeFalse();
})->with([
    [['published_at' => null]], [['expires_at' => null]],
    [['published_at' => '2026-11-21 01:00:00+00']], [['deleted_at' => '2026-11-20 00:00:00+00']],
]);

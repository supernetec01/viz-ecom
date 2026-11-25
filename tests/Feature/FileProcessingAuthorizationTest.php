<?php

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Events\FileProcessingRetryRequested;
use App\Filament\Resources\FileReviewResource\Pages\ListFileReviews;
use App\Filament\Widgets\FileReviewStats;
use App\Jobs\ProcessCandidateFile;
use App\Services\RetryFileProcessing;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Support/FileProcessingFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('candidate-files');
    Cache::flush();
    Queue::fake();
});

it('denies manual file review access to staff and employer accounts', function (Role $role) {
    $this->actingAs(jobManager($role))->get('/app/file-can-xem-tay')->assertForbidden();
})->with([Role::Staff, Role::Employer]);

it('denies direct retries and the dashboard widget to non admin roles', function (Role $role) {
    $actor = jobManager($role);
    $file = processingFile();
    $file->forceFill(['scan_status' => 'error', 'review_required' => true])->save();
    expect(fn () => app(RetryFileProcessing::class)->retry($actor, $file->id))->toThrow(AuthorizationException::class);
    Livewire::actingAs($actor)->test(FileReviewStats::class)->assertForbidden();
    Queue::assertNothingPushed();
})->with([Role::Staff, Role::Employer]);

it('only exposes IDs states errors attempts and times to admins without candidate identity or file content links', function () {
    $file = processingFile();
    $file->forceFill(['scan_status' => 'error', 'scan_error_code' => 'scanner_unavailable', 'review_required' => true])->save();
    $applicationId = DB::table('applications')->value('id');
    $this->actingAs(jobManager(Role::Admin))->get('/app/file-can-xem-tay')->assertOk()
        ->assertDontSee('PRIVATE_ORIGINAL_NAME')->assertDontSee('PRIVATE_CANDIDATE_NAME')->assertDontSee('private@example.test');
    Livewire::test(ListFileReviews::class)->assertCanSeeTableRecords([$file])->assertSee($applicationId)
        ->assertDontSee('PRIVATE_ORIGINAL_NAME')->assertDontSee('PRIVATE_CANDIDATE_NAME')->assertDontSee('private@example.test');
});

it('retries only failed preview work and emits an audit hook with actor time file and stage', function () {
    $actor = jobManager(Role::Admin);
    $file = processingFile();
    $file->forceFill(['scan_status' => 'clean', 'scanned_sha256' => $file->sha256, 'scan_attempts' => 1,
        'preview_status' => 'failed', 'preview_attempts' => 3, 'processing_attempts' => 3, 'preview_error_code' => 'conversion_failed', 'review_required' => true])->save();
    Event::fake([FileProcessingRetryRequested::class]);
    app(RetryFileProcessing::class)->retry($actor, $file->id);
    $file->refresh();
    expect($file->scan_status)->toBe('clean')->and($file->scan_attempts)->toBe(1)->and($file->preview_attempts)->toBe(0)
        ->and($file->processing_attempts)->toBe(0)->and($file->preview_status)->toBe('pending')->and($file->processing_version)->toBe(1);
    Queue::assertPushed(ProcessCandidateFile::class, fn ($job) => $job->fileId === $file->id && $job->version === 1 && $job->connection === 'database');
    Event::assertDispatched(FileProcessingRetryRequested::class, fn ($event) => $event->actorId === $actor->id && $event->fileId === $file->id
        && $event->stage === 'preview' && $event->occurredAt !== '');
});

it('restarts scanning rather than only preview when the original changed', function () {
    $actor = jobManager(Role::Admin);
    $file = processingFile();
    $file->forceFill(['scan_status' => 'clean', 'scanned_sha256' => $file->sha256, 'scan_attempts' => 1,
        'preview_status' => 'failed', 'preview_attempts' => 3, 'review_required' => true])->save();
    Storage::disk('candidate-files')->put($file->path, 'changed original');
    app(RetryFileProcessing::class)->retry($actor, $file->id);
    expect($file->fresh()->scan_status)->toBe('pending')->and($file->fresh()->scan_attempts)->toBe(0)->and($file->fresh()->scanned_sha256)->toBeNull();
});

it('never offers or accepts retry for infected files even for an admin', function () {
    $actor = jobManager(Role::Admin);
    $file = processingFile();
    $file->forceFill(['scan_status' => 'infected', 'review_required' => true])->save();
    Livewire::actingAs($actor)->test(ListFileReviews::class)->assertTableActionHidden('retry', $file);
    expect(fn () => app(RetryFileProcessing::class)->retry($actor, $file->id))->toThrow(AuthorizationException::class);
    Queue::assertNothingPushed();
});

it('rechecks revoked admin rights before changing processing state', function () {
    $actor = jobManager(Role::Admin);
    $file = processingFile();
    $file->forceFill(['scan_status' => 'error', 'review_required' => true])->save();
    $actor->forceFill(['status' => UserStatus::Locked])->save();
    expect(fn () => app(RetryFileProcessing::class)->retry($actor, $file->id))->toThrow(AuthorizationException::class);
    expect($file->fresh()->scan_status)->toBe('error');
});

it('shows the review count on the admin dashboard and does not create candidate content routes', function () {
    $file = processingFile();
    $file->forceFill(['scan_status' => 'error', 'review_required' => true])->save();
    Livewire::actingAs(jobManager(Role::Admin))->test(FileReviewStats::class)->assertSee(__('files.manual_review'))->assertSee('1');
    foreach (Route::getRoutes() as $route) {
        expect($route->uri())->not->toMatch('~(?:^|/)(?:cv|portfolio|ho-so|candidate-files)(?:/|$)~');
    }
});

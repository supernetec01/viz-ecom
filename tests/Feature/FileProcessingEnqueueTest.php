<?php

use App\Contracts\VirusScanner;
use App\Services\SubmitApplication;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeVirusScanner;

require_once __DIR__.'/../Support/CvFixtures.php';

uses(RefreshDatabase::class);

it('enqueues IDs in the same database transaction and never scans inside the submission request even with sync configured', function () {
    Storage::fake('candidate-files');
    config(['queue.default' => 'sync']);
    $scanner = new FakeVirusScanner;
    app()->instance(VirusScanner::class, $scanner);
    $job = publishManagedJob(managedJob(jobManager()));
    app(SubmitApplication::class)->submit($job->id, ['full_name' => 'Synthetic', 'phone' => '0912345678', 'submission_token' => str_repeat('c', 64)], docxUpload(), null);
    expect($scanner->calls)->toBe(0)->and(DB::table('queue_jobs')->where('queue', 'candidate-files')->count())->toBe(1);
    $queue = DB::table('queue_jobs')->where('queue', 'candidate-files')->first();
    expect($queue->queue)->toBe('candidate-files');
    expect($queue->payload)->not->toContain('0912345678', 'Synthetic', 'cv.docx');
    expect(DB::table('files')->value('scan_status'))->toBe('pending');
});

it('rolls back the application files and queued work together if enqueueing fails', function () {
    Storage::fake('candidate-files');
    $job = publishManagedJob(managedJob(jobManager()));
    DB::listen(function (QueryExecuted $query) {
        if (str_starts_with($query->sql, 'insert into "queue_jobs"')) {
            throw new RuntimeException('Synthetic queue write failure');
        }
    });
    expect(fn () => app(SubmitApplication::class)->submit($job->id,
        ['full_name' => 'Synthetic', 'phone' => '0912345678', 'submission_token' => str_repeat('d', 64)], docxUpload(), null))->toThrow(ValidationException::class);
    expect(DB::table('queue_jobs')->count())->toBe(0)->and(DB::table('applications')->count())->toBe(0)->and(DB::table('files')->count())->toBe(0);
    expect(Storage::disk('candidate-files')->allFiles())->toBe([]);
});

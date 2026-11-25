<?php

use App\Services\CandidateFileLease;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->freezeTime();
    Storage::fake('candidate-files');
    config(['candidate-cleanup.staging_root' => storage_path('framework/testing/cleanup-'.Str::uuid()),
        'file-processing.staging_root' => storage_path('framework/testing/processor-cleanup-'.Str::uuid())]);
});

function cleanupTestFile(bool $referenced = false, int $age = 90000, string $kind = 'cv'): string
{
    $path = $kind.'/1/1/'.Str::ulid().'/'.Str::uuid().'.pdf';
    Storage::disk('candidate-files')->put($path, 'synthetic-only');
    touch(Storage::disk('candidate-files')->path($path), now()->timestamp - $age);
    if ($referenced) {
        DB::table('files')->insert(['path' => $path, 'original_name' => 'do-not-log.pdf', 'mime_type' => 'application/pdf',
            'size' => 14, 'sha256' => hash('sha256', 'synthetic-only'), 'created_at' => now(), 'updated_at' => now()]);
    }

    return $path;
}

function cleanupBaseline(): void
{
    for ($i = 0; $i < 4; $i++) {
        cleanupTestFile(true);
    }
}

function runCandidateCleanup(bool $force = false): array
{
    $exit = Artisan::call('candidate-files:cleanup', ['--json' => true, '--force' => $force]);

    return [json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR), $exit];
}

it('defaults to dry run and preserves every file while listing old orphan candidates and unattached metadata ids', function () {
    cleanupBaseline();
    $orphan = cleanupTestFile();
    [$report, $exit] = runCandidateCleanup();
    expect($exit)->toBe(0)->and($report['deleted'])->toBe(0)->and($report['candidates'])->toBe(1);
    expect($report['unattached_metadata_count'])->toBe(4);
    Storage::disk('candidate-files')->assertExists($orphan);
    expect(Artisan::output())->not->toContain('do-not-log.pdf');
});

it('only deletes an old orphan with force and retains path references and unattached file metadata', function (int $age) {
    cleanupBaseline();
    $orphan = cleanupTestFile(false, $age);
    [$report, $exit] = runCandidateCleanup(true);
    expect($exit)->toBe(0)->and($report['deleted'])->toBe(1);
    Storage::disk('candidate-files')->assertMissing($orphan);
    expect(DB::table('files')->count())->toBe(4)->and(count(Storage::disk('candidate-files')->allFiles()))->toBe(4);
})->with([86400, 90000]);

it('preserves younger files and files referenced as previews', function () {
    $young = cleanupTestFile(false, 86399);
    $preview = cleanupTestFile();
    $original = cleanupTestFile(true);
    DB::table('files')->where('path', $original)->update(['preview_path' => $preview]);
    [$report, $exit] = runCandidateCleanup(true);
    expect($exit)->toBe(0)->and($report['deleted'])->toBe(0);
    Storage::disk('candidate-files')->assertExists([$young, $preview, $original]);
});

it('stops before deletion when orphan percentage is abnormal or the metadata table is unexpectedly empty', function () {
    for ($i = 0; $i < 12; $i++) {
        cleanupTestFile();
    }
    [$report, $exit] = runCandidateCleanup(true);
    expect($exit)->toBe(1)->and($report['deleted'])->toBe(0);
    expect($report['safety_stops'])->toContain('orphan_ratio', 'empty_metadata');
    expect(count(Storage::disk('candidate-files')->allFiles()))->toBe(12);
});

it('skips a file held by an active writer lease', function () {
    cleanupBaseline();
    $path = cleanupTestFile();
    $lease = CandidateFileLease::acquire('candidate:'.$path);
    [$report, $exit] = runCandidateCleanup(true);
    expect($exit)->toBe(0)->and($report['deleted'])->toBe(0)->and($report['skipped']['active'])->toBe(1);
    Storage::disk('candidate-files')->assertExists($path);
    $lease->release();
});

it('preserves file metadata and bytes for a soft deleted application', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $path = cleanupTestFile(true);
    $fileId = DB::table('files')->where('path', $path)->value('id');
    DB::table('applications')->insert(['id' => Str::ulid(), 'job_id' => $job->id, 'company_id' => $job->company_id,
        'full_name' => 'Synthetic', 'phone_normalized' => '0912345678', 'cv_file_id' => $fileId,
        'consent_given' => true, 'consented_at' => now(), 'submitted_at' => now(), 'deleted_at' => now()]);
    [$report, $exit] = runCandidateCleanup(true);
    expect($exit)->toBe(0)->and($report['deleted'])->toBe(0)->and($report['unattached_metadata_count'])->toBe(0);
    Storage::disk('candidate-files')->assertExists($path);
});

it('never follows file or directory symlinks to an outside target', function () {
    $outside = storage_path('framework/testing/outside-'.Str::uuid());
    mkdir($outside, 0700, true);
    file_put_contents($outside.'/keep.pdf', 'outside-original');
    $root = Storage::disk('candidate-files')->path('');
    if (! @symlink($outside.'/keep.pdf', $root.'/link.pdf')) {
        unlink($outside.'/keep.pdf');
        rmdir($outside);
        test()->markTestSkipped('Symlink creation requires OS permission; covered on Ubuntu CI.');
    }
    symlink($outside, $root.'/linked-directory');
    try {
        [$report] = runCandidateCleanup(true);
        expect($report['deleted'])->toBe(0)->and($report['skipped']['symlink'])->toBe(2);
        expect(file_get_contents($outside.'/keep.pdf'))->toBe('outside-original');
    } finally {
        unlink($root.'/link.pdf');
        unlink($root.'/linked-directory');
        unlink($outside.'/keep.pdf');
        rmdir($outside);
    }
});

it('rechecks modification time immediately before unlink and keeps a file changed during the database check', function () {
    cleanupBaseline();
    $path = cleanupTestFile();
    $full = Storage::disk('candidate-files')->path($path);
    $seen = 0;
    DB::listen(function (QueryExecuted $query) use (&$seen, $path, $full) {
        if (str_contains($query->sql, 'exists') && in_array($path, $query->bindings, true) && ++$seen === 2) {
            touch($full, now()->timestamp);
        }
    });
    [$report, $exit] = runCandidateCleanup(true);
    expect($exit)->toBe(0)->and($report['deleted'])->toBe(0)->and($report['skipped']['changed'])->toBe(1);
    Storage::disk('candidate-files')->assertExists($path);
});

it('aborts without deletion if the database is unavailable and records a sanitized run summary', function () {
    $path = cleanupTestFile();
    Log::spy();
    DB::beforeExecuting(function (string $sql) {
        if (str_contains($sql, '"files"')) {
            throw new RuntimeException('Synthetic unavailable database with private details');
        }
    });
    [$report, $exit] = runCandidateCleanup(true);
    expect($exit)->toBe(1)->and($report['deleted'])->toBe(0);
    Storage::disk('candidate-files')->assertExists($path);
    Log::shouldHaveReceived('warning')->with('candidate_cleanup_failed', Mockery::on(fn ($summary) => isset($summary['scanned'], $summary['skipped'], $summary['deleted'])
        && ! str_contains(json_encode($summary), 'private details')))->once();
});

it('only cleans owned staging and skips a staging directory currently inspected', function (string $kind, string $setting) {
    cleanupBaseline();
    $uuid = (string) Str::uuid();
    $root = config($setting);
    mkdir($root.'/'.$uuid, 0700, true);
    $path = $root.'/'.$uuid.'/input.pdf';
    file_put_contents($path, 'synthetic');
    touch($path, now()->timestamp - 90000);
    chmod($path, 0444);
    $lease = CandidateFileLease::acquire($kind.':'.$uuid);
    [$active] = runCandidateCleanup(true);
    expect($active['deleted'])->toBe(0);
    $lease->release();
    [$idle] = runCandidateCleanup(true);
    expect($idle['deleted'])->toBe(1);
    expect(is_file($path))->toBeFalse();
    rmdir($root.'/'.$uuid);
    rmdir($root);
})->with([
    ['pdf-staging', 'candidate-cleanup.staging_root'],
    ['processor-staging', 'file-processing.staging_root'],
]);

it('requires production opt in even when force is supplied', function () {
    cleanupBaseline();
    $path = cleanupTestFile();
    app()->detectEnvironment(fn () => 'production');
    [$report, $exit] = runCandidateCleanup(true);
    expect($exit)->toBe(1)->and($report['safety_stops'])->toContain('production_force_disabled');
    Storage::disk('candidate-files')->assertExists($path);
});

it('rejects a cleanup root containing parent traversal without touching files outside the upload root', function () {
    $outside = storage_path('framework/testing/parent-'.Str::uuid());
    mkdir($outside, 0700, true);
    file_put_contents($outside.'/keep.pdf', 'outside');
    config(['candidate-cleanup.staging_root' => $outside.'/../'.basename($outside)]);
    [$report, $exit] = runCandidateCleanup(true);
    expect($exit)->toBe(1)->and($report['deleted'])->toBe(0);
    expect(file_get_contents($outside.'/keep.pdf'))->toBe('outside');
    unlink($outside.'/keep.pdf');
    rmdir($outside);
});

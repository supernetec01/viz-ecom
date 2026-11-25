<?php

use App\Services\StoreCandidateFile;
use App\Services\SubmitApplication;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/CvFixtures.php';
require_once __DIR__.'/../Support/PortfolioFixtures.php';

uses(RefreshDatabase::class);

it('preserves files if a commit acknowledgement fails after records have been committed', function () {
    Storage::fake('candidate-files');
    $job = publishManagedJob(managedJob(jobManager()));
    $armed = true;
    Event::listen(TransactionCommitted::class, function () use (&$armed) {
        if ($armed) {
            $armed = false;
            throw new RuntimeException('Synthetic lost commit acknowledgement');
        }
    });
    expect(fn () => app(SubmitApplication::class)->submit($job->id, [
        'full_name' => 'Synthetic candidate', 'phone' => '0912345678', 'submission_token' => str_repeat('a', 64),
    ], docxUpload(), null, portfolioImage()))->toThrow(ValidationException::class);
    expect(DB::table('applications')->count())->toBe(1)->and(DB::table('files')->count())->toBe(2);
    foreach (DB::table('files')->pluck('path') as $path) {
        Storage::disk('candidate-files')->assertExists($path);
    }
});

function transactionTestData(): array
{
    return ['full_name' => 'Synthetic candidate', 'phone' => '0912345678', 'submission_token' => str_repeat('b', 64)];
}

it('removes a newly written CV when inserting its metadata fails', function () {
    Storage::fake('candidate-files');
    $job = publishManagedJob(managedJob(jobManager()));
    DB::listen(function (QueryExecuted $query) {
        if (str_starts_with($query->sql, 'insert into "files"')) {
            throw new RuntimeException('Synthetic metadata write failure');
        }
    });
    expect(fn () => app(SubmitApplication::class)->submit($job->id, transactionTestData(), docxUpload(), null))->toThrow(ValidationException::class);
    expect(DB::table('files')->count())->toBe(0)->and(DB::table('applications')->count())->toBe(0);
    expect(Storage::disk('candidate-files')->allFiles())->toBe([]);
});

it('removes a partial disk write if the storage operation throws before returning metadata', function () {
    $disk = Storage::fake('candidate-files');
    $job = publishManagedJob(managedJob(jobManager()));
    $proxy = Mockery::mock($disk)->makePartial();
    $proxy->shouldReceive('put')->once()->andReturnUsing(function ($path, $stream, $options) use ($disk) {
        $disk->put($path, $stream, $options);
        throw new RuntimeException('Synthetic interrupted stream');
    });
    Storage::shouldReceive('disk')->with('candidate-files')->andReturn($proxy);
    expect(fn () => app(SubmitApplication::class)->submit($job->id, transactionTestData(), docxUpload(), null))->toThrow(ValidationException::class);
    expect($disk->allFiles())->toBe([])->and(DB::table('files')->count())->toBe(0);
});

it('continues cleaning the second file when cleanup of the first file fails', function () {
    Storage::fake('candidate-files');
    $job = publishManagedJob(managedJob(jobManager()));
    $storage = app(StoreCandidateFile::class);
    $proxy = Mockery::mock($storage)->makePartial();
    $calls = 0;
    $proxy->shouldReceive('discard')->twice()->andReturnUsing(function ($file) use ($storage, &$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('Synthetic disk deletion unavailable');
        }
        $storage->discard($file);
    });
    app()->instance(StoreCandidateFile::class, $proxy);
    DB::listen(function (QueryExecuted $query) {
        if (str_starts_with($query->sql, 'insert into "applications"')) {
            throw new RuntimeException('Synthetic application failure');
        }
    });
    expect(fn () => app(SubmitApplication::class)->submit($job->id, transactionTestData(), docxUpload(), null, portfolioImage()))->toThrow(ValidationException::class);
    expect($calls)->toBe(2)->and(DB::table('files')->count())->toBe(0)->and(DB::table('applications')->count())->toBe(0);
    expect(count(Storage::disk('candidate-files')->allFiles()))->toBe(1);
});

it('sanitizes a database connection exception with candidate bindings and removes uncommitted files', function () {
    Storage::fake('candidate-files');
    $job = publishManagedJob(managedJob(jobManager()));
    Log::spy();
    DB::listen(function (QueryExecuted $query) {
        if (str_starts_with($query->sql, 'insert into "applications"')) {
            throw new QueryException('pgsql', $query->sql, $query->bindings, new PDOException('Synthetic connection reset'));
        }
    });
    try {
        app(SubmitApplication::class)->submit($job->id, transactionTestData(), docxUpload(), null);
        test()->fail('The failed transaction must not be accepted.');
    } catch (ValidationException $exception) {
        expect($exception->getPrevious())->toBeNull();
        expect($exception->errors())->toBe(['application' => [__('applications.save_failed')]]);
    }
    expect(DB::table('applications')->count())->toBe(0)->and(Storage::disk('candidate-files')->allFiles())->toBe([]);
    Log::shouldNotHaveReceived('error');
});

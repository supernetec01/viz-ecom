<?php

use App\Models\Company;
use App\Models\Job;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function applicationStorageRow(Job $job, array $overrides = []): array
{
    return array_replace([
        'id' => (string) Str::ulid(), 'job_id' => $job->id, 'company_id' => $job->company_id,
        'full_name' => 'Ứng viên thử', 'phone_normalized' => '0912345678', 'email' => null,
        'consent_given' => true, 'consented_at' => now(), 'submitted_at' => now(), 'source' => 'zalo-nhom_1',
        'created_at' => now(), 'updated_at' => now(),
    ], $overrides);
}

function applicationFileRow(array $overrides = []): array
{
    return array_replace([
        'path' => 'cv/1/1/'.Str::ulid().'/'.Str::uuid().'.pdf', 'original_name' => 'cv-thu.pdf',
        'mime_type' => 'application/pdf', 'size' => 1024, 'sha256' => hash('sha256', 'synthetic-file'),
        'created_at' => now(), 'updated_at' => now(),
    ], $overrides);
}

it('rejects duplicate active normalized phones for the same job directly in PostgreSQL', function () {
    $job = managedJob(jobManager());
    DB::table('applications')->insert(applicationStorageRow($job));
    expect(fn () => DB::transaction(fn () => DB::table('applications')->insert(applicationStorageRow($job))))->toThrow(QueryException::class);
    expect(DB::table('applications')->count())->toBe(1);
});

it('rejects a company that does not own the referenced job even if both foreign rows exist', function () {
    $job = managedJob(jobManager());
    $other = Company::create(['name' => 'Other company']);
    DB::table('applications')->insert(applicationStorageRow($job, ['company_id' => $other->id]));
})->throws(QueryException::class);

it('requires valid ULIDs normalized phones explicit consent safe source and valid application status in the database', function (array $invalid) {
    $job = managedJob(jobManager());
    DB::table('applications')->insert(applicationStorageRow($job, $invalid));
})->with([
    'not ULID' => [['id' => 'not-a-ulid']], 'overflow ULID' => [['id' => '8'.str_repeat('A', 25)]],
    'missing name' => [['full_name' => null]], 'blank name' => [['full_name' => '   ']],
    'international unnormalized' => [['phone_normalized' => '+84912345678']], 'spaces unnormalized' => [['phone_normalized' => '84 912 345 678']],
    'short phone' => [['phone_normalized' => '0912']], 'no consent' => [['consent_given' => false]], 'missing consent time' => [['consented_at' => null]],
    'unsafe source' => [['source' => '<script>']], 'long source' => [['source' => str_repeat('a', 65)]], 'unknown status' => [['status' => 'unknown']],
    'missing job' => [['job_id' => 999999999]], 'missing company' => [['company_id' => 999999999]],
    'missing CV' => [['cv_file_id' => 999999999]], 'missing portfolio' => [['portfolio_file_id' => 999999999]],
    'unsafe portfolio scheme' => [['portfolio_url' => 'javascript:alert(1)']],
])->throws(QueryException::class);

it('stores a public ULID default new status nullable optional data and UTC submission timestamps', function () {
    $this->freezeTime();
    $job = managedJob(jobManager());
    $row = applicationStorageRow($job, ['source' => null]);
    DB::table('applications')->insert($row);
    $stored = DB::table('applications')->where('id', $row['id'])->first();
    expect(Str::isUlid($stored->id))->toBeTrue();
    expect($stored->status)->toBe('new');
    expect($stored->cv_file_id)->toBeNull();
    expect($stored->portfolio_file_id)->toBeNull();
    expect($stored->portfolio_url)->toBeNull();
    expect($stored->email)->toBeNull();
    expect($stored->source)->toBeNull();
    expect($stored->deleted_at)->toBeNull();
    expect(DB::scalar("SELECT current_setting('TimeZone')"))->toBe('UTC');
    expect(DB::scalar("SELECT data_type FROM information_schema.columns WHERE table_name = 'applications' AND column_name = 'submitted_at'"))->toBe('timestamp with time zone');
    expect(DB::scalar("SELECT data_type FROM information_schema.columns WHERE table_name = 'applications' AND column_name = 'consented_at'"))->toBe('timestamp with time zone');
});

it('allows the same normalized phone on another job and a replacement after soft deletion but refuses restoration over a duplicate', function () {
    $staff = jobManager();
    $job = managedJob($staff);
    $other = managedJob($staff);
    $first = applicationStorageRow($job);
    DB::table('applications')->insert($first);
    DB::table('applications')->insert(applicationStorageRow($other));
    DB::table('applications')->where('id', $first['id'])->update(['deleted_at' => now()]);
    DB::table('applications')->insert(applicationStorageRow($job));
    expect(DB::table('applications')->count())->toBe(3);
    expect(fn () => DB::transaction(fn () => DB::table('applications')->where('id', $first['id'])->update(['deleted_at' => null])))->toThrow(QueryException::class);
});

it('preserves applications and file references when the job and company are soft deleted', function () {
    $job = managedJob(jobManager());
    $fileId = DB::table('files')->insertGetId(applicationFileRow());
    $row = applicationStorageRow($job, ['cv_file_id' => $fileId]);
    DB::table('applications')->insert($row);
    $job->delete();
    $job->company->delete();
    expect(DB::table('applications')->where('id', $row['id'])->value('cv_file_id'))->toBe($fileId);
    expect(DB::table('files')->where('id', $fileId)->exists())->toBeTrue();
    expect(DB::table('applications')->where('id', $row['id'])->value('deleted_at'))->toBeNull();
});

it('restricts hard deletion of referenced jobs companies and files instead of silently cascading applications', function (string $table) {
    $job = managedJob(jobManager());
    $fileId = DB::table('files')->insertGetId(applicationFileRow());
    DB::table('applications')->insert(applicationStorageRow($job, ['cv_file_id' => $fileId, 'portfolio_file_id' => $fileId]));
    $id = match ($table) {
        'jobs' => $job->id, 'companies' => $job->company_id, 'files' => $fileId
    };
    DB::table($table)->where('id', $id)->delete();
})->with(['jobs', 'companies', 'files'])->throws(QueryException::class);

it('stores file metadata with default pending scan status and supports optional preview paths', function () {
    $fileId = DB::table('files')->insertGetId(applicationFileRow(['preview_path' => 'previews/'.Str::uuid().'.pdf']));
    $file = DB::table('files')->where('id', $fileId)->first();
    expect($file->scan_status)->toBe('pending');
    expect($file->sha256)->toBe(hash('sha256', 'synthetic-file'));
    expect($file->preview_path)->toStartWith('previews/');
});

it('accepts a portfolio URL or file but rejects both together', function () {
    $job = managedJob(jobManager());
    $fileId = DB::table('files')->insertGetId(applicationFileRow());
    DB::table('applications')->insert(applicationStorageRow($job, ['portfolio_url' => 'https://example.org/work']));
    DB::table('applications')->insert(applicationStorageRow($job, ['phone_normalized' => '0912345679', 'portfolio_file_id' => $fileId]));
    expect(fn () => DB::transaction(fn () => DB::table('applications')->insert(applicationStorageRow($job, ['phone_normalized' => '0912345670', 'portfolio_url' => 'https://example.org/work', 'portfolio_file_id' => $fileId]))))->toThrow(QueryException::class);
});

it('rejects invalid file sizes hashes scan states and duplicate storage paths at the database boundary', function (array $invalid) {
    $file = applicationFileRow();
    DB::table('files')->insert($file);
    DB::table('files')->insert(array_replace(applicationFileRow(), $invalid === ['duplicate' => true] ? ['path' => $file['path']] : $invalid));
})->with([
    'empty' => [['size' => 0]], 'negative' => [['size' => -1]], 'oversized' => [['size' => 10485761]],
    'bad hash' => [['sha256' => 'not-a-hash']], 'missing hash' => [['sha256' => null]],
    'invalid status' => [['scan_status' => 'unknown']], 'duplicate path' => [['duplicate' => true]],
])->throws(QueryException::class);

it('accepts the maximum file size and all documented application and scan statuses', function () {
    foreach (['pending', 'clean', 'infected'] as $status) {
        DB::table('files')->insert(applicationFileRow(['scan_status' => $status, 'size' => 10485760]));
    }
    $job = managedJob(jobManager());
    foreach (['new', 'viewed', 'interview', 'hired', 'rejected'] as $index => $status) {
        DB::table('applications')->insert(applicationStorageRow($job, ['phone_normalized' => '091234567'.$index, 'status' => $status]));
    }
    expect(DB::table('files')->count())->toBe(3);
    expect(DB::table('applications')->count())->toBe(5);
});

it('can roll back and reapply only the new storage migration without losing existing company or job records', function () {
    $job = managedJob(jobManager());
    $boostSchema = require database_path('migrations/2026_11_28_000025_create_plans_and_job_boosts.php');
    $boostSchema->down();
    $lifecycle = require database_path('migrations/2026_11_28_000024_add_grant_lifecycle_history.php');
    $lifecycle->down();
    $confirmation = require database_path('migrations/2026_11_28_000023_add_payment_confirmation_integrity.php');
    $confirmation->down();
    $orderScope = require database_path('migrations/2026_11_27_000022_scope_access_orders_to_jobs.php');
    $orderScope->down();
    $limits = require database_path('migrations/2026_11_27_000020_add_access_limits_and_audit_events.php');
    $limits->down();
    $detail = require database_path('migrations/2026_11_26_000019_add_application_edit_version_and_audit_logs.php');
    $detail->down();
    $grants = require database_path('migrations/2026_11_26_000017_create_orders_and_access_grants.php');
    $grants->down();
    $mail = require database_path('migrations/2026_11_26_000016_create_application_mail_counters.php');
    $mail->down();
    $migration = require database_path('migrations/2026_11_21_000013_create_files_and_applications.php');
    $migration->down();
    expect(Schema::hasTable('files'))->toBeFalse();
    expect(Schema::hasTable('applications'))->toBeFalse();
    expect(DB::table('jobs')->where('id', $job->id)->exists())->toBeTrue();
    $migration->up();
    $mail->up();
    $grants->up();
    $detail->up();
    $limits->up();
    $orderScope->up();
    $confirmation->up();
    $lifecycle->up();
    $boostSchema->up();
    DB::table('applications')->insert(applicationStorageRow($job));
    expect(DB::table('applications')->count())->toBe(1);
});

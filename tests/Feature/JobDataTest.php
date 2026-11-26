<?php

use App\Enums\EmploymentType;
use App\Enums\JobStatus;
use App\Models\Company;
use App\Models\Job;
use App\Models\JobCategory;
use App\Models\Location;
use App\Models\User;
use App\Support\JobData;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function recruitmentJobData(): array
{
    $creator = User::factory()->create();
    $company = Company::create(['name' => 'Verified company']);
    $company->forceFill(['verified_at' => now(), 'verified_by' => $creator->id])->save();

    return [
        'company_id' => $company->id,
        'category_id' => JobCategory::create(['name' => 'Sales', 'slug' => 'sales'])->id,
        'location_id' => Location::create(['name' => 'Hà Nội', 'code' => '01'])->id,
        'ward_name' => 'Phường thử nghiệm', 'work_address' => 'Số 12, đường thử nghiệm',
        'title' => 'Nhân viên kinh doanh', 'description' => 'Mô tả', 'requirements' => 'Yêu cầu', 'benefits' => 'Quyền lợi',
        'salary_min' => 10000000, 'salary_max' => 20000000, 'negotiable' => false,
        'experience_years' => 1, 'vacancies' => 2, 'employment_type' => 'full_time', 'created_by' => $creator->id,
    ];
}

function makeRecruitmentJob(array $data): Job
{
    $job = new Job($data);
    $job->created_by = $data['created_by'];
    $job->save();

    return $job;
}

dataset('invalid job values', [
    'negative minimum' => [['salary_min' => -1]],
    'negative maximum' => [['salary_max' => -1]],
    'inverted range' => [['salary_min' => 20000001]],
    'negative experience' => [['experience_years' => -1]],
    'zero vacancies' => [['vacancies' => 0]],
    'invalid employment' => [['employment_type' => 'unknown']],
]);

it('rejects invalid values in job form validation', function (array $invalid) {
    JobData::validate(array_replace(recruitmentJobData(), $invalid));
})->with('invalid job values')->throws(ValidationException::class);

it('enforces salary counts and employment constraints in PostgreSQL', function (array $invalid) {
    DB::table('jobs')->insert(array_replace(recruitmentJobData(), ['slug' => 'test'], $invalid));
})->with('invalid job values')->throws(QueryException::class);

it('rejects fractional VND and unknown references before persistence', function (array $invalid) {
    JobData::validate(array_replace(recruitmentJobData(), $invalid));
})->with([[['salary_min' => 1.5]], [['company_id' => 999999]], [['location_id' => 999999]], [['category_id' => 999999]]])->throws(ValidationException::class);

it('requires a verified nondeleted company in both form validation and model creation', function (string $reason) {
    $data = recruitmentJobData();
    $company = Company::findOrFail($data['company_id']);
    match ($reason) {
        'unverified' => $company->forceFill(['verified_at' => null, 'verified_by' => null])->save(),
        'partial' => $company->forceFill(['verified_by' => null])->save(),
        'deleted' => $company->delete(),
    };
    expect(fn () => JobData::validate($data))->toThrow(ValidationException::class)
        ->and(fn () => makeRecruitmentJob($data))->toThrow(ValidationException::class);
    expect(Job::count())->toBe(0);
})->with(['unverified', 'partial', 'deleted']);

it('creates drafts with relationships integer salaries and collision safe stable slugs', function () {
    $data = recruitmentJobData();
    $first = makeRecruitmentJob($data);
    $second = makeRecruitmentJob($data);
    $first->delete();
    $third = makeRecruitmentJob($data);
    expect($first->slug)->toBe('nhan-vien-kinh-doanh')
        ->and($second->slug)->toBe('nhan-vien-kinh-doanh-2')
        ->and($third->slug)->toBe('nhan-vien-kinh-doanh-3')
        ->and($second->status)->toBe(JobStatus::Draft)
        ->and($second->employment_type)->toBe(EmploymentType::FullTime)
        ->and($second->salary_min)->toBe(10000000)
        ->and($second->company->id)->toBe($data['company_id'])
        ->and($second->creator->id)->toBe($data['created_by'])
        ->and($second->category->id)->toBe($data['category_id'])
        ->and($second->location->id)->toBe($data['location_id']);
    $second->update(['title' => 'Changed title']);
    expect($second->fresh()->slug)->toBe('nhan-vien-kinh-doanh-2')->and(Job::count())->toBe(2);
});

it('accepts negotiable salary and open ended ranges', function (array $salary) {
    $job = makeRecruitmentJob(array_replace(recruitmentJobData(), $salary));
    expect($job->exists)->toBeTrue();
})->with([[['negotiable' => true, 'salary_min' => null, 'salary_max' => null]], [['salary_min' => 0, 'salary_max' => null]], [['salary_min' => null, 'salary_max' => 0]]]);

it('keeps the expiry entered for a new draft and records publication in UTC', function () {
    $expiry = now()->addDays(7)->startOfSecond();
    $job = makeRecruitmentJob(recruitmentJobData() + ['expires_at' => $expiry]);
    expect($job->fresh()->expires_at->equalTo($expiry))->toBeTrue()->and($job->published_at)->toBeNull();
    $job->transitionTo(JobStatus::Pending);
    $job->transitionTo(JobStatus::Published);
    expect($job->fresh()->published_at->offset)->toBe(0)
        ->and(DB::scalar("SELECT current_setting('TimeZone')"))->toBe('UTC');
});

it('only allows approved state transitions in the persisted model', function (string $from, string $to, bool $allowed) {
    $job = makeRecruitmentJob(recruitmentJobData());
    if ($from !== 'draft') {
        $job->transitionTo(JobStatus::Pending);
    }
    if (in_array($from, ['published', 'expired', 'closed'])) {
        $job->expires_at = now()->addDays(7);
        $job->transitionTo(JobStatus::Published);
    }
    if ($from === 'paused') {
        $job->expires_at = now()->addDays(7);
        $job->transitionTo(JobStatus::Published);
    }
    if (in_array($from, ['paused', 'expired', 'closed'])) {
        $job->transitionTo(JobStatus::from($from));
    }
    $job->expires_at ??= now()->addDays(7);
    if ($allowed) {
        $job->transitionTo(JobStatus::from($to));
        expect($job->fresh()->status->value)->toBe($to);
        if ($to === 'draft') {
            expect($job->published_at)->toBeNull()->and($job->expires_at)->toBeNull();
        }
    } else {
        expect(fn () => $job->transitionTo(JobStatus::from($to)))->toThrow(ValidationException::class);
        expect($job->fresh()->status->value)->toBe($from);
    }
})->with(function () {
    $approved = ['draft' => ['pending', 'published'], 'pending' => ['draft', 'published'], 'published' => ['paused', 'expired', 'closed'], 'paused' => ['published', 'closed', 'expired', 'draft'], 'expired' => ['draft'], 'closed' => []];
    foreach ($approved as $from => $targets) {
        foreach (array_keys($approved) as $to) {
            if ($from !== $to) {
                yield "$from to $to" => [$from, $to, in_array($to, $targets, true)];
            }
        }
    }
});

it('refuses initial published state and publishing without a future expiry', function () {
    $data = recruitmentJobData();
    $new = new Job($data);
    $new->forceFill(['created_by' => $data['created_by'], 'status' => JobStatus::Published]);
    expect(fn () => $new->save())->toThrow(ValidationException::class);
    $job = makeRecruitmentJob($data);
    $job->transitionTo(JobStatus::Pending);
    expect(fn () => $job->transitionTo(JobStatus::Published))->toThrow(ValidationException::class);
    $job->refresh();
    $job->expires_at = now()->subDay();
    expect(fn () => $job->transitionTo(JobStatus::Published))->toThrow(ValidationException::class);
});

it('rejects a stale model trying to overwrite a newer state', function () {
    $job = makeRecruitmentJob(recruitmentJobData());
    $stale = $job->fresh();
    $job->transitionTo(JobStatus::Pending);
    $job->expires_at = now()->addDays(7);
    $job->transitionTo(JobStatus::Published);
    expect(fn () => $stale->transitionTo(JobStatus::Pending))->toThrow(ValidationException::class);
    expect($job->fresh()->status)->toBe(JobStatus::Published);
});

it('enforces unique slugs foreign keys valid states and dates in PostgreSQL', function (array $invalid) {
    $data = recruitmentJobData();
    DB::table('jobs')->insert($data + ['slug' => 'existing']);
    DB::table('jobs')->insert(array_replace($data, ['slug' => 'new'], $invalid));
})->with([[['slug' => 'existing']], [['status' => 'unknown']], [['status' => 'published']], [['company_id' => 999999]], [['published_at' => '2026-11-20 10:00:00+00', 'expires_at' => '2026-11-20 09:00:00+00']]])->throws(QueryException::class);

it('preserves queue rows through migration up and down and keeps database queue usable', function () {
    // Roll back the later child table first, matching the real migrator's reverse order.
    $grants = require database_path('migrations/2026_11_26_000017_create_orders_and_access_grants.php');
    $grants->down();
    $mail = require database_path('migrations/2026_11_26_000016_create_application_mail_counters.php');
    $mail->down();
    $storage = require database_path('migrations/2026_11_21_000013_create_files_and_applications.php');
    $storage->down();
    $reviews = require database_path('migrations/2026_11_21_000011_create_job_reviews_and_notifications.php');
    $reviews->down();
    $migration = require database_path('migrations/2026_11_20_000007_create_recruitment_jobs.php');
    $migration->down();
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => 'existing-payload', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
    $migration->up();
    expect(config('queue.connections.database.table'))->toBe('queue_jobs');
    $this->assertDatabaseHas('queue_jobs', ['payload' => 'existing-payload']);
    Queue::connection('database')->pushRaw('{"displayName":"T10 queue probe"}');
    expect(DB::table('queue_jobs')->count())->toBe(2)->and(Job::count())->toBe(0);
    $migration->down();
    expect(DB::table('jobs')->count())->toBe(2);
    $migration->up();
    $reviews->up();
    $storage->up();
    $mail->up();
    $grants->up();
});

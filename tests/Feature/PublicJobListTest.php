<?php

use App\Enums\JobStatus;
use App\Enums\Role;
use App\Models\JobReview;
use App\Services\ListPublicJobs;
use App\Services\ReviewJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('excludes every nonpublic status and expired published jobs from the public list', function () {
    $staff = jobManager();
    $visible = publishManagedJob(managedJob($staff));
    $visible->forceFill(['title' => 'Tin công khai hợp lệ'])->saveQuietly();
    foreach ([JobStatus::Draft, JobStatus::Pending, JobStatus::Paused, JobStatus::Closed, JobStatus::Expired] as $status) {
        $job = $status === JobStatus::Draft ? managedJob($staff) : publishManagedJob(managedJob($staff));
        DB::table('jobs')->where('id', $job->id)->update(['status' => $status->value, 'title' => 'Không hiện '.$status->value]);
    }
    $overdue = publishManagedJob(managedJob($staff));
    DB::table('jobs')->where('id', $overdue->id)->update(['title' => 'Không hiện quá hạn', 'published_at' => now()->subDays(2), 'expires_at' => now()->subSecond()]);
    $response = $this->get('/viec-lam')->assertOk()->assertSee('Tin công khai hợp lệ');
    foreach (['draft', 'pending', 'paused', 'closed', 'expired', 'quá hạn'] as $hidden) {
        $response->assertDontSee('Không hiện '.$hidden);
    }
});

it('paginates equal publication timestamps without duplicate or missing jobs', function () {
    $staff = jobManager();
    $ids = [];
    for ($index = 0; $index < 27; $index++) {
        $ids[] = publishManagedJob(managedJob($staff))->id;
    }
    DB::table('jobs')->whereIn('id', $ids)->update(['published_at' => now()->subDay()->startOfSecond()]);
    $seen = [];
    for ($page = 1; $page <= 3; $page++) {
        $response = $this->get('/viec-lam?page='.$page)->assertOk();
        preg_match_all('/data-job-id="(\d+)"/', $response->getContent(), $matches);
        $pageIds = array_map('intval', $matches[1]);
        expect(count($pageIds))->toBe($page === 3 ? 3 : ListPublicJobs::PER_PAGE);
        $seen = array_merge($seen, $pageIds);
    }
    expect($seen)->toBe(array_reverse($ids))->and(count(array_unique($seen)))->toBe(27);
});

it('sorts publication time before using descending id to break ties', function () {
    $staff = jobManager();
    $olderId = publishManagedJob(managedJob($staff));
    $newerId = publishManagedJob(managedJob($staff));
    DB::table('jobs')->where('id', $newerId->id)->update(['published_at' => now()->subDay()]);
    $jobs = $this->get('/viec-lam')->assertOk()->viewData('jobs');
    expect($jobs->pluck('id')->all())->toBe([$olderId->id, $newerId->id]);
});

it('excludes jobs exactly at expiry and scheduled in the future without waiting for the scheduler', function () {
    $this->freezeTime();
    $staff = jobManager();
    $atExpiry = publishManagedJob(managedJob($staff));
    $stillOpen = publishManagedJob(managedJob($staff));
    $future = publishManagedJob(managedJob($staff));
    DB::table('jobs')->where('id', $atExpiry->id)->update(['published_at' => now()->subDay(), 'expires_at' => now()]);
    DB::table('jobs')->where('id', $stillOpen->id)->update(['expires_at' => now()->addSecond()]);
    DB::table('jobs')->where('id', $future->id)->update(['published_at' => now()->addDay()]);
    expect($this->get('/viec-lam')->assertOk()->viewData('jobs')->pluck('id')->all())->toBe([$stillOpen->id]);
    $this->travel(2)->seconds();
    expect($this->get('/viec-lam')->assertOk()->viewData('jobs')->total())->toBe(0);
});

it('excludes deleted jobs and jobs from deleted or unverified companies', function (string $reason) {
    $job = publishManagedJob(managedJob(jobManager()));
    match ($reason) {
        'job_deleted' => $job->delete(),
        'company_deleted' => $job->company->delete(),
        'unverified' => $job->company->forceFill(['verified_at' => null])->save(),
        'partial_verification' => $job->company->forceFill(['verified_by' => null])->save(),
    };
    expect($this->get('/viec-lam')->assertOk()->viewData('jobs')->total())->toBe(0);
})->with(['job_deleted', 'company_deleted', 'unverified', 'partial_verification']);

it('never exposes rejected or pending jobs through supplied status or company parameters', function () {
    $staff = jobManager();
    $pending = heldReviewJob($staff);
    $rejected = heldReviewJob($staff);
    app(ReviewJob::class)->reject(jobManager(Role::Admin), $rejected, ['reason' => 'INTERNAL_REJECTION_ONLY']);
    $response = $this->get('/viec-lam?status=draft&company_id='.$pending->company_id)->assertOk();
    expect($response->viewData('jobs')->total())->toBe(0);
    $response->assertDontSee('INTERNAL_REJECTION_ONLY');
});

it('escapes job cards and does not load or render internal content or scan metadata', function () {
    $staff = jobManager();
    $job = publishManagedJob(managedJob($staff));
    DB::table('jobs')->where('id', $job->id)->update([
        'title' => '<script>publicCardXss()</script>', 'description' => 'PRIVATE_CONTENT_ONLY',
        'content_scan_reasons' => json_encode([['field' => 'description', 'rule' => 'external_link', 'value' => 'https://internal-review-secret.test']]),
    ]);
    (new JobReview)->forceFill(['job_id' => $job->id, 'reviewed_by' => $staff->id, 'decision' => 'approved', 'reason' => 'PRIVATE_REVIEW_ONLY', 'reviewed_at' => now()])->save();
    $response = $this->get('/viec-lam')->assertOk()->assertSee('<script>publicCardXss()</script>')
        ->assertDontSee('<script>publicCardXss()</script>', false)
        ->assertDontSee('PRIVATE_CONTENT_ONLY')->assertDontSee('PRIVATE_REVIEW_ONLY')->assertDontSee('internal-review-secret.test');
    $listed = $response->viewData('jobs')->sole();
    expect(array_keys($listed->getAttributes()))->not->toContain('description', 'created_by', 'content_scan_reasons', 'content_scanned_at')
        ->and($listed->relationLoaded('latestReview'))->toBeFalse();
});

it('renders complete workplace information and Vietnamese dates on public cards', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    DB::table('jobs')->where('id', $job->id)->update(['published_at' => '2020-01-01 20:00:00+00', 'expires_at' => now()->addDays(7)]);
    $this->get('/viec-lam')->assertOk()->assertSee($job->workplaceAddress())
        ->assertSee('Đăng 02/01/2020')->assertSee(__('ui.jobs.employment_types.full_time'));
});

it('labels missing legacy work addresses without guessing them from the company office', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $job->company->update(['address' => 'OFFICE_NOT_WORKPLACE']);
    DB::table('jobs')->where('id', $job->id)->update(['ward_name' => null, 'work_address' => null]);
    $this->get('/viec-lam')->assertOk()->assertSee('Hà Nội')->assertSee(__('public.address_incomplete'))->assertDontSee('OFFICE_NOT_WORKPLACE');
});

it('renders a useful empty state and handles invalid or out of range pages', function () {
    $this->get('/viec-lam')->assertOk()->assertSee(__('public.no_jobs_title'));
    publishManagedJob(managedJob(jobManager()));
    foreach (['0', '-1', 'abc', '1e999', '%5B%5D'] as $page) {
        expect($this->get('/viec-lam?page='.$page)->assertOk()->viewData('jobs')->currentPage())->toBe(1);
    }
    $this->get('/viec-lam?page=999')->assertOk()->assertSee(__('public.page_empty_title'))
        ->assertSee(__('public.first_page'))->assertDontSee('Trang 999 / 1');
    expect($this->get('/viec-lam?page[]=1')->assertOk()->viewData('jobs')->currentPage())->toBe(1);
});

it('eager loads card data with a bounded number of queries as the page fills', function () {
    $staff = jobManager();
    for ($index = 0; $index < 12; $index++) {
        publishManagedJob(managedJob($staff));
    }
    DB::enableQueryLog();
    $jobs = app(ListPublicJobs::class)->paginate();
    foreach ($jobs as $job) {
        $job->workplaceAddress();
        $job->category->name;
        $job->company->name;
    }
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(count($queries))->toBeLessThanOrEqual(5)->and($jobs->count())->toBe(12);
});

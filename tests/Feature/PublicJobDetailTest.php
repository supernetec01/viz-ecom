<?php

use App\Enums\JobStatus;
use App\Models\JobCategory;
use App\Models\Location;
use App\Services\ShowPublicJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns 404 without a canonical redirect or content for a draft', function () {
    $job = managedJob(jobManager());
    $this->get('/viec-lam/'.$job->slug.'-'.$job->id)->assertNotFound()->assertDontSee($job->title);
    $this->get('/viec-lam/old-slug-'.$job->id)->assertNotFound()->assertHeaderMissing('Location');
});

it('returns 404 for a pending job even when its id is known', function () {
    $job = heldReviewJob(jobManager());
    $this->get('/viec-lam/'.$job->slug.'-'.$job->id)->assertNotFound()->assertDontSee($job->title);
});

it('renders a published job with its complete workplace and sanitized content', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $this->get('/viec-lam/'.$job->slug.'-'.$job->id)->assertOk()->assertSee($job->title)->assertSee($job->workplaceAddress())
        ->assertSee(__('ui.jobs.description'))->assertSee(__('ui.jobs.requirements'))->assertSee(__('ui.jobs.benefits'))
        ->assertSee(__('applications.heading'))->assertSee('name="submission_token"', false)->assertDontSee('name="robots"', false)
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('renders a paused job with full content a pause notice and noindex without an application form', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $job->transitionTo(JobStatus::Paused);
    $response = $this->get('/viec-lam/'.$job->slug.'-'.$job->id)->assertOk();
    $response->assertSee(__('public.detail_states.paused'))->assertSee(__('ui.jobs.description'))
        ->assertSee('name="robots" content="noindex, follow"', false)->assertDontSee('<form', false)->assertDontSee('JobPosting');
    expect($response->viewData('accepting'))->toBeFalse();
});

it('renders an expired job with its full content expiry notice and noindex', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $job->transitionTo(JobStatus::Expired);
    $response = $this->get('/viec-lam/'.$job->slug.'-'.$job->id)->assertOk();
    $response->assertSee(__('public.detail_states.expired'))->assertSee(__('ui.jobs.description'))
        ->assertSee('name="robots" content="noindex, follow"', false)->assertDontSee('<form', false)->assertDontSee('JobPosting');
    expect($response->viewData('accepting'))->toBeFalse();
});

it('returns only minimal closed job information and never its description requirements benefits or salary', function () {
    $job = managedJob(jobManager());
    $job->update(['description' => 'PRIVATE_CLOSED_DESCRIPTION', 'requirements' => 'PRIVATE_CLOSED_REQUIREMENTS', 'benefits' => 'PRIVATE_CLOSED_BENEFITS', 'salary_min' => 12345678, 'salary_max' => 87654321]);
    publishManagedJob($job)->transitionTo(JobStatus::Closed);
    $response = $this->get('/viec-lam/'.$job->slug.'-'.$job->id)->assertOk();
    $response->assertSee($job->title)->assertSee($job->company->name)->assertSee($job->workplaceAddress())
        ->assertSee(__('public.detail_states.closed'))->assertSee('name="robots" content="noindex, follow"', false)
        ->assertDontSee('PRIVATE_CLOSED_DESCRIPTION')->assertDontSee('PRIVATE_CLOSED_REQUIREMENTS')->assertDontSee('PRIVATE_CLOSED_BENEFITS')
        ->assertDontSee('12.345.678')->assertDontSee('87.654.321')->assertDontSee(__('ui.jobs.description'))
        ->assertDontSee(__('ui.jobs.requirements'))->assertDontSee(__('ui.jobs.benefits'))->assertDontSee('job-salary')
        ->assertDontSee('<form', false)->assertDontSee('JobPosting');
    foreach (['description', 'requirements', 'benefits', 'salary_min', 'salary_max', 'employment_type', 'vacancies'] as $field) {
        expect($response->viewData('job')->{$field})->toBeNull();
    }
    expect($response->viewData('accepting'))->toBeFalse();
});

it('returns 404 for missing malformed oversized and deleted job ids', function () {
    foreach (['/viec-lam/missing-999999', '/viec-lam/missing-0', '/viec-lam/missing-abc', '/viec-lam/missing-9223372036854775808', '/viec-lam/missing-9999999999999999999999999'] as $path) {
        $this->get($path)->assertNotFound();
    }
    $job = publishManagedJob(managedJob(jobManager()));
    $job->delete();
    $this->get('/viec-lam/'.$job->slug.'-'.$job->id)->assertNotFound();
});

it('redirects stale slugs to the current canonical slug with 301', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    DB::table('jobs')->where('id', $job->id)->update(['slug' => 'ten-moi-cua-tin']);
    $this->get('/viec-lam/'.$job->slug.'-'.$job->id)->assertStatus(301)
        ->assertRedirect(route('jobs.show', ['slug' => 'ten-moi-cua-tin', 'id' => $job->id]));
    $this->get('/viec-lam/ten-moi-cua-tin-'.$job->id)->assertOk();
});

it('treats overdue published or paused jobs as expired at the exact boundary without waiting for T42', function (JobStatus $status) {
    $this->freezeTime();
    $job = publishManagedJob(managedJob(jobManager()));
    if ($status === JobStatus::Paused) {
        $job->transitionTo($status);
    }
    DB::table('jobs')->where('id', $job->id)->update(['published_at' => now()->subDay(), 'expires_at' => now()]);
    $response = $this->get('/viec-lam/'.$job->slug.'-'.$job->id)->assertOk()->assertSee(__('public.detail_states.expired'));
    expect($response->viewData('accepting'))->toBeFalse();
})->with([JobStatus::Published, JobStatus::Paused]);

it('returns 404 when the company is deleted unverified or its job was never publicly published', function (string $reason) {
    $job = publishManagedJob(managedJob(jobManager()));
    match ($reason) {
        'deleted' => $job->company->delete(),
        'unverified' => $job->company->forceFill(['verified_at' => null])->save(),
        'partial' => $job->company->forceFill(['verified_by' => null])->save(),
        'future' => DB::table('jobs')->where('id', $job->id)->update(['published_at' => now()->addDay()]),
    };
    $this->get('/viec-lam/'.$job->slug.'-'.$job->id)->assertNotFound();
    $this->get('/viec-lam/wrong-slug-'.$job->id)->assertNotFound()->assertHeaderMissing('Location');
})->with(['deleted', 'unverified', 'partial', 'future']);

it('sanitizes legacy rich HTML and excludes internal review and search metadata from the public response', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    DB::table('jobs')->where('id', $job->id)->update([
        'description' => '<p><strong>Hợp lệ</strong></p><script>detailXss()</script><img src=x onerror="bad()"><a href="javascript:bad()">bad</a>',
        'content_scan_reasons' => json_encode([['field' => 'description', 'rule' => 'external_link', 'value' => 'INTERNAL_SCAN_SECRET']]),
    ]);
    $response = $this->get('/viec-lam/'.$job->slug.'-'.$job->id)->assertOk()->assertSee('<strong>Hợp lệ</strong>', false)
        ->assertDontSee('detailXss()', false)->assertDontSee('onerror=', false)->assertDontSee('javascript:', false)->assertDontSee('INTERNAL_SCAN_SECRET');
    expect(array_keys($response->viewData('job')->getAttributes()))->not->toContain('content_scan_reasons', 'content_scanned_at', 'search_text', 'created_by');
});

it('suggests only open jobs in the same category preferring the same province and excluding the current job', function () {
    $staff = jobManager();
    $job = publishManagedJob(managedJob($staff));
    $samePlace = publishManagedJob(managedJob($staff));
    $otherPlace = managedJob($staff);
    $otherPlace->update(['location_id' => Location::create(['code' => '56', 'name' => 'Khánh Hòa'])->id]);
    publishManagedJob($otherPlace);
    $otherCategory = managedJob($staff);
    $otherCategory->update(['category_id' => JobCategory::create(['slug' => 'other', 'name' => 'Khác'])->id]);
    publishManagedJob($otherCategory);
    $paused = publishManagedJob(managedJob($staff));
    $paused->transitionTo(JobStatus::Paused);
    expect(app(ShowPublicJob::class)->similar($job)->pluck('id')->all())->toBe([$samePlace->id, $otherPlace->id]);
});

it('offers at most three stable minimal suggestions on every nonreceiving detail state', function (JobStatus $status) {
    $staff = jobManager();
    $job = publishManagedJob(managedJob($staff));
    $job->transitionTo($status);
    $ids = [];
    for ($index = 0; $index < 4; $index++) {
        $ids[] = publishManagedJob(managedJob($staff))->id;
    }
    DB::table('jobs')->whereIn('id', $ids)->update(['published_at' => now()->subDay()]);
    $response = $this->get('/viec-lam/'.$job->slug.'-'.$job->id)->assertOk()->assertSee(__('public.similar_jobs'));
    expect($response->viewData('similar')->pluck('id')->all())->toBe(array_slice(array_reverse($ids), 0, 3));
})->with([JobStatus::Paused, JobStatus::Expired, JobStatus::Closed]);

it('links public list titles to canonical detail and company pages without a temporary POST route', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $url = route('jobs.show', ['slug' => $job->slug, 'id' => $job->id]);
    $this->get('/viec-lam')->assertOk()->assertSee($url);
    $this->get($url)->assertOk()->assertSee(route('companies.show', ['id' => $job->company_id]))->assertSee(route('applications.store', ['id' => $job->id]));
    $this->post('/viec-lam/'.$job->slug.'-'.$job->id)->assertStatus(405);
});

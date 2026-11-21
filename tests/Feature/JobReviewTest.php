<?php

use App\Enums\JobStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Filament\Resources\JobResource\Pages\ListJobs;
use App\Filament\Resources\JobResource\Pages\ViewJob;
use App\Filament\Resources\PendingJobResource\Pages\ListPendingJobs;
use App\Models\JobReview;
use App\Services\ReviewJob;
use App\Services\SubmitJob;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);
beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('app')));

it('shows only pending jobs and escaped exact scan reasons to admins', function () {
    $admin = jobManager(Role::Admin);
    $pending = heldReviewJob(jobManager());
    $draft = managedJob(jobManager());
    $published = publishManagedJob(managedJob(jobManager()));
    $pending->transitionTo(JobStatus::Draft);
    $pending->update(['description' => '<a href="https://outside.test/apply">Trang nội bộ</a>']);
    $pending = app(SubmitJob::class)->submit($pending->creator, $pending);
    $this->actingAs($admin);
    Livewire::test(ListPendingJobs::class)->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$draft, $published])->assertSee('https://outside.test/apply');
    $this->get('/app/duyet-tin')->assertOk();
});

it('publishes held content only on admin approval and records the decision', function () {
    $admin = jobManager(Role::Admin);
    $staff = jobManager();
    $job = heldReviewJob($staff);
    $findings = $job->content_scan_reasons;
    $expires = now()->addDays(10)->startOfSecond();
    $result = app(ReviewJob::class)->approve($admin, $job, ['expires_at' => $expires, 'reviewed_by' => 999999]);
    expect($result->status)->toBe(JobStatus::Published)->and($result->published_at)->not->toBeNull()
        ->and($result->expires_at->equalTo($expires))->toBeTrue()->and($result->content_scan_reasons)->toEqual($findings);
    $review = JobReview::sole();
    expect($review->reviewed_by)->toBe($admin->id)->and($review->decision)->toBe('approved')
        ->and($review->reviewed_at->offset)->toBe(0)->and($review->reason)->toBeNull();
    expect($staff->notifications()->count())->toBe(1)->and(DB::table('queue_jobs')->count())->toBe(0);
});

it('requires a nonblank bounded plain text reason when rejecting', function ($reason) {
    $job = heldReviewJob(jobManager());
    expect(fn () => app(ReviewJob::class)->reject(jobManager(Role::Admin), $job, ['reason' => $reason]))->toThrow(ValidationException::class);
    expect($job->fresh()->status)->toBe(JobStatus::Pending)->and(JobReview::count())->toBe(0)->and(DB::table('notifications')->count())->toBe(0);
})->with([null, '', '   ', str_repeat('x', 2001), '<script>bad()</script>']);

it('returns rejected jobs to draft with no publication dates and a reason visible to assigned staff', function () {
    $staff = jobManager();
    $job = heldReviewJob($staff);
    app(ReviewJob::class)->reject(jobManager(Role::Admin), $job, ['reason' => '  Bỏ thông tin liên hệ ngoài & sửa mô tả  ']);
    $job->refresh();
    expect($job->status)->toBe(JobStatus::Draft)->and($job->published_at)->toBeNull()->and($job->expires_at)->toBeNull()
        ->and($job->latestReview->reason)->toBe('Bỏ thông tin liên hệ ngoài & sửa mô tả');
    $this->actingAs($staff)->get('/app/tin-tuyen-dung/'.$job->id)->assertOk()->assertSee('Bỏ thông tin liên hệ ngoài & sửa mô tả');
    $this->actingAs(jobManager())->get('/app/tin-tuyen-dung/'.$job->id)->assertNotFound();
    expect($job->toJson())->not->toContain('latest_review', 'Bỏ thông tin', 'content_scan_reasons');
});

it('does not publish or notify on invalid approval expiry', function ($expiry) {
    $staff = jobManager();
    $job = heldReviewJob($staff);
    expect(fn () => app(ReviewJob::class)->approve(jobManager(Role::Admin), $job, ['expires_at' => $expiry]))->toThrow(ValidationException::class);
    expect($job->fresh()->status)->toBe(JobStatus::Pending)->and(JobReview::count())->toBe(0)->and($staff->notifications()->count())->toBe(0);
})->with([null, 'invalid', '2000-01-01 00:00:00']);

it('notifies only currently assigned active staff without copying content or rejection reasons', function () {
    $old = jobManager();
    $job = heldReviewJob($old);
    $current = jobManager();
    $locked = jobManager();
    $locked->forceFill(['status' => UserStatus::Locked])->save();
    $employer = jobManager(Role::Employer);
    $job->company->staff()->sync([$current->id, $locked->id, $employer->id]);
    app(ReviewJob::class)->reject(jobManager(Role::Admin), $job, ['reason' => 'Không đăng số 0901234567']);
    expect($old->notifications()->count())->toBe(0)->and($current->notifications()->count())->toBe(1)
        ->and($locked->notifications()->count())->toBe(0)->and($employer->notifications()->count())->toBe(0);
    $data = $current->notifications()->sole()->data;
    expect(json_encode($data))->not->toContain('0901234567', 'Liên hệ', 'reason');
    expect($data['actions'][0]['url'])->toBe(url('/app/tin-tuyen-dung/'.$job->id));
    $this->actingAs($current);
    Livewire::test(DatabaseNotifications::class)->set('isOpen', true)->assertSee(__('ui.review.notification_rejected'));
});

it('keeps review history across resubmission and denies duplicate or stale decisions', function () {
    $admin = jobManager(Role::Admin);
    $staff = jobManager();
    $job = heldReviewJob($staff);
    $stale = $job->fresh();
    app(ReviewJob::class)->reject($admin, $job, ['reason' => 'Sửa lại']);
    expect(fn () => app(ReviewJob::class)->approve($admin, $stale, ['expires_at' => now()->addDays(7)]))->toThrow(AuthorizationException::class);
    $job->refresh()->update(['expires_at' => now()->addDays(7)]);
    $resubmitted = app(SubmitJob::class)->submit($staff, $job);
    app(ReviewJob::class)->approve($admin, $resubmitted, ['expires_at' => now()->addDays(7)]);
    expect(JobReview::where('job_id', $job->id)->count())->toBe(2)->and($staff->notifications()->count())->toBe(2);
    expect(fn () => app(ReviewJob::class)->reject($admin, $resubmitted, ['reason' => 'Trùng']))->toThrow(AuthorizationException::class);
    expect(JobReview::count())->toBe(2);
});

it('rolls back status and review when database notification persistence fails', function () {
    $staff = jobManager();
    $job = heldReviewJob($staff);
    DB::statement("ALTER TABLE notifications ADD CONSTRAINT test_block_notifications CHECK (type = 'never')");
    expect(fn () => app(ReviewJob::class)->reject(jobManager(Role::Admin), $job, ['reason' => 'Sửa lại']))->toThrow(QueryException::class);
    expect($job->fresh()->status)->toBe(JobStatus::Pending)->and(JobReview::count())->toBe(0)->and($staff->notifications()->count())->toBe(0);
});

it('approves and rejects through the admin queue with required action forms', function () {
    $admin = jobManager(Role::Admin);
    $job = heldReviewJob(jobManager());
    $other = heldReviewJob(jobManager());
    $this->actingAs($admin);
    Livewire::test(ListPendingJobs::class)->callAction(TestAction::make('reject')->table($job), data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);
    Livewire::test(ListPendingJobs::class)->callAction(TestAction::make('reject')->table($job), data: ['reason' => 'Sửa nội dung'])
        ->assertHasNoActionErrors()->assertCanNotSeeTableRecords([$job]);
    Livewire::test(ListPendingJobs::class)->callAction(TestAction::make('approve')->table($other), data: ['expires_at' => now()->addDays(7)->toDateTimeString()])
        ->assertHasNoActionErrors()->assertCanNotSeeTableRecords([$other]);
    expect($job->fresh()->status)->toBe(JobStatus::Draft)->and($other->fresh()->status)->toBe(JobStatus::Published);
});

it('submits clean and held jobs from the management interface', function () {
    $staff = jobManager();
    $clean = managedJob($staff);
    $held = managedJob($staff);
    $held->update(['description' => 'Liên hệ Zalo']);
    $this->actingAs($staff);
    Livewire::test(ListJobs::class)->callAction(TestAction::make('submit')->table($clean), data: ['expires_at' => now()->addDays(7)->toDateTimeString()])
        ->assertHasNoActionErrors()->assertNotified(__('ui.review.submitted_published'));
    Livewire::test(ViewJob::class, ['record' => $held->id])->callAction('submit', data: ['expires_at' => now()->addDays(7)->toDateTimeString()])
        ->assertHasNoActionErrors()->assertNotified(__('ui.review.submitted_pending'))->assertSee(__('ui.jobs.states.pending'));
    expect($clean->fresh()->status)->toBe(JobStatus::Published)->and($held->fresh()->status)->toBe(JobStatus::Pending);
});

it('keeps a pending job unpublished if its company verification was revoked', function () {
    $job = heldReviewJob(jobManager());
    $job->company->forceFill(['verified_at' => null, 'verified_by' => null])->save();
    expect(fn () => app(ReviewJob::class)->approve(jobManager(Role::Admin), $job, ['expires_at' => now()->addDays(7)]))->toThrow(ValidationException::class);
    expect($job->fresh()->status)->toBe(JobStatus::Pending)->and(JobReview::count())->toBe(0);
});

it('warns the admin when a legacy held job is missing its work address', function () {
    $job = heldReviewJob(jobManager());
    DB::table('jobs')->where('id', $job->id)->update(['ward_name' => null, 'work_address' => null]);
    $this->actingAs(jobManager(Role::Admin));
    Livewire::test(ListPendingJobs::class)->callAction(TestAction::make('approve')->table($job), data: ['expires_at' => now()->addDays(7)->toDateTimeString()])
        ->assertNotified(__('ui.review.cannot_approve'));
    expect($job->fresh()->status)->toBe(JobStatus::Pending)->and(JobReview::count())->toBe(0);
});

it('escapes outside URLs in the review queue and preview modal', function () {
    $job = heldReviewJob(jobManager());
    DB::table('jobs')->where('id', $job->id)->update(['content_scan_reasons' => json_encode([
        ['field' => 'description', 'rule' => 'external_link', 'value' => 'https://outside.test/<script>badReview()</script>'],
    ])]);
    $this->actingAs(jobManager(Role::Admin));
    Livewire::test(ListPendingJobs::class)->mountAction(TestAction::make('view')->table($job))
        ->assertSee('https://outside.test/<script>badReview()</script>')->assertDontSee('<script>badReview()</script>', escape: false);
});

it('denies an approval after the admin role is revoked while its modal is open', function () {
    $admin = jobManager(Role::Admin);
    $job = heldReviewJob(jobManager());
    $this->actingAs($admin);
    $page = Livewire::test(ListPendingJobs::class)->mountAction(TestAction::make('approve')->table($job));
    $admin->forceFill(['role' => Role::Staff])->save();
    $page->callMountedAction()->assertForbidden();
    expect($job->fresh()->status)->toBe(JobStatus::Pending)->and(JobReview::count())->toBe(0);
});

<?php

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Jobs\QueueApplicationSummaries;
use App\Jobs\SendApplicationSummary;
use App\Mail\ApplicationSummaryMail;
use App\Models\User;
use App\Services\ApplicationSummaryQueue;
use App\Services\SendApplicationSummaryMail;
use App\Services\SubmitApplication;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(DatabaseMigrations::class);

beforeEach(function () {
    Mail::fake();
});

function summaryFixture(): array
{
    $job = publishManagedJob(managedJob(jobManager()));
    $employer = User::factory()->create(['role' => Role::Employer]);
    $job->company->employers()->attach($employer);

    return [$job, $employer];
}

function summarySubmission($job, int $number = 1): void
{
    app(SubmitApplication::class)->submit($job->id, ['full_name' => 'PRIVATE_SUMMARY_CANDIDATE',
        'phone' => '091234'.str_pad((string) $number, 4, '0', STR_PAD_LEFT), 'email' => 'private-summary@example.test',
        'portfolio_url' => 'https://private-portfolio.example.test', 'submission_token' => hash('sha256', 'summary-'.$number)], null, null);
}

it('queues summary checks after commit even with sync configured and contains no candidate data in mail or payload', function () {
    [$job, $employer] = summaryFixture();
    summarySubmission($job);
    Mail::assertNothingSent();
    $row = DB::table('queue_jobs')->where('queue', 'application-mail')->first();
    expect($row)->not->toBeNull();
    expect($row->payload)->not->toContain('PRIVATE_SUMMARY_CANDIDATE', '091234', 'private-summary@example.test', 'private-portfolio');
    app(ApplicationSummaryQueue::class)->fanOut($job->id, $job->company_id);
    app(SendApplicationSummaryMail::class)->send($job->id, $job->company_id, $employer->id);
    Mail::assertSent(ApplicationSummaryMail::class, function ($mail) use ($job, $employer) {
        $html = $mail->render();
        expect($html)->toContain(e($job->title), e($job->company->name), url('/app'))
            ->not->toContain('PRIVATE_SUMMARY_CANDIDATE', '091234', 'private-summary@example.test', 'private-portfolio');
        expect($mail->newCount)->toBe(1)->and($mail->attachments)->toBe([]);

        return $mail->hasTo($employer->email);
    });
});

it('rechecks a locked or changed role recipient at delivery time', function (string $change) {
    [$job, $employer] = summaryFixture();
    summarySubmission($job);
    $employer->forceFill($change === 'locked' ? ['status' => UserStatus::Locked] : ['role' => Role::Staff])->save();
    app(SendApplicationSummaryMail::class)->send($job->id, $job->company_id, $employer->id);
    Mail::assertNothingSent();
})->with(['locked', 'role']);

it('rejects other companies detached members admins and staff at delivery', function (string $kind) {
    [$job, $employer] = summaryFixture();
    summarySubmission($job);
    if ($kind === 'detached') {
        $job->company->employers()->detach($employer);
    } elseif ($kind === 'other_company') {
        $employer = User::factory()->create(['role' => Role::Employer]);
    } else {
        $employer->forceFill(['role' => $kind])->save();
    }
    app(SendApplicationSummaryMail::class)->send($job->id, $job->company_id, $employer->id);
    Mail::assertNothingSent();
})->with(['detached', 'other_company', 'admin', 'staff']);

it('resolves all current eligible company members when the fanout worker runs', function () {
    [$job, $employer] = summaryFixture();
    summarySubmission($job);
    $newMember = User::factory()->create(['role' => Role::Employer]);
    $job->company->employers()->attach($newMember);
    Queue::fake();
    (new QueueApplicationSummaries($job->id, $job->company_id))->handle(app(ApplicationSummaryQueue::class));
    Queue::assertPushed(SendApplicationSummary::class, 2);
    Queue::assertPushed(SendApplicationSummary::class, fn ($queued) => $queued->userId === $newMember->id);
});

it('sends at most once per job recipient hour and batches only new submissions at the exact boundary', function () {
    $this->freezeTime();
    [$job, $employer] = summaryFixture();
    $sender = app(SendApplicationSummaryMail::class);
    summarySubmission($job);
    $sender->send($job->id, $job->company_id, $employer->id);
    $sender->send($job->id, $job->company_id, $employer->id);
    summarySubmission($job, 2);
    summarySubmission($job, 3);
    expect($sender->send($job->id, $job->company_id, $employer->id))->toBe(3600);
    $this->travel(3599)->seconds();
    expect($sender->send($job->id, $job->company_id, $employer->id))->toBe(1);
    $this->travel(1)->seconds();
    $sender->send($job->id, $job->company_id, $employer->id);
    $sender->send($job->id, $job->company_id, $employer->id);
    Mail::assertSent(ApplicationSummaryMail::class, 2);
    Mail::assertSent(ApplicationSummaryMail::class, fn ($mail) => $mail->newCount === 2);
    expect(DB::table('application_mail_receipts')->value('notified_total'))->toBe(3);
});

it('does not roll back a submission if enqueueing the summary fails and can recover its counter later', function () {
    [$job] = summaryFixture();
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('Synthetic queue failure'));
    summarySubmission($job);
    expect(DB::table('applications')->count())->toBe(1)->and(DB::table('application_mail_totals')->value('total'))->toBe(1);
});

it('marks uncertain SMTP delivery without resending the same batch on repeated worker delivery', function () {
    [$job, $employer] = summaryFixture();
    summarySubmission($job);
    Mail::shouldReceive('to')->once()->with($employer->email)->andThrow(new RuntimeException('Lost SMTP acknowledgement'));
    app(SendApplicationSummaryMail::class)->send($job->id, $job->company_id, $employer->id);
    app(SendApplicationSummaryMail::class)->send($job->id, $job->company_id, $employer->id);
    expect(DB::table('applications')->count())->toBe(1)->and(DB::table('application_mail_receipts')->value('status'))->toBe('uncertain');
});

it('does not repeat a durable sending claim left by a crashed worker', function () {
    [$job, $employer] = summaryFixture();
    summarySubmission($job);
    DB::table('application_mail_receipts')->insert(['job_id' => $job->id, 'user_id' => $employer->id, 'notified_total' => 1, 'status' => 'sending', 'attempted_at' => now()]);
    app(SendApplicationSummaryMail::class)->send($job->id, $job->company_id, $employer->id);
    Mail::assertNothingSent();
});

it('limits pre send failures to three runs with delayed retries', function () {
    Queue::fake();
    $sender = Mockery::mock(SendApplicationSummaryMail::class);
    $sender->shouldReceive('send')->times(3)->andThrow(new RuntimeException('Before SMTP'));
    foreach ([0, 1, 2] as $failures) {
        (new SendApplicationSummary(1, 1, 1, $failures))->handle($sender);
    }
    Queue::assertPushed(SendApplicationSummary::class, 2);
});

it('requeues the hourly delivery without sending early', function () {
    $sender = Mockery::mock(SendApplicationSummaryMail::class);
    $sender->shouldReceive('send')->once()->andReturn(3599);
    $queued = (new SendApplicationSummary(1, 1, 1))->withFakeQueueInteractions();
    $queued->handle($sender);
    $queued->assertReleased(3599);
    Mail::assertNothingSent();
});

it('recovers durable counters through the dispatch command without reading candidate records', function () {
    [$job] = summaryFixture();
    summarySubmission($job);
    Queue::fake();
    $this->artisan('application-summaries:dispatch')->assertSuccessful();
    Queue::assertPushed(QueueApplicationSummaries::class, fn ($queued) => $queued->jobId === $job->id && $queued->queue === 'application-mail');
});

it('does not record counters or send mail when the application transaction is rolled back', function () {
    [$job] = summaryFixture();
    try {
        DB::transaction(function () use ($job) {
            summarySubmission($job);
            throw new RuntimeException('Rollback');
        });
    } catch (RuntimeException) {
    }
    expect(DB::table('applications')->count())->toBe(0)->and(DB::table('application_mail_totals')->count())->toBe(0)
        ->and(DB::table('queue_jobs')->where('queue', 'application-mail')->count())->toBe(0);
    Mail::assertNothingSent();
});

<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Job;
use App\Models\JobReview;
use App\Models\User;
use App\Support\JobData;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class ReviewJob
{
    public function approve(User $actor, Job $job, array $input): Job
    {
        return $this->decide($actor, $job, 'approved', $input);
    }

    public function reject(User $actor, Job $job, array $input): Job
    {
        return $this->decide($actor, $job, 'rejected', $input);
    }

    private function decide(User $actor, Job $job, string $decision, array $input): Job
    {
        return DB::transaction(function () use ($actor, $job, $decision, $input): Job {
            $job = Job::whereKey($job->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('review', $job);
            if ($decision === 'approved') {
                $data = Validator::make($input, ['expires_at' => ['required', 'date', 'after:now']], [], ['expires_at' => __('ui.jobs.expires_at')])->validate();
                JobData::validate($job->getAttributes());
                $job->expires_at = $data['expires_at'];
                $job->transitionTo(JobStatus::Published);
                $reason = null;
            } else {
                if (is_string($input['reason'] ?? null)) {
                    $input['reason'] = trim($input['reason']);
                }
                $data = Validator::make($input, ['reason' => ['required', 'string', 'max:2000', 'regex:/^[^<>]*$/u']], [], ['reason' => __('ui.review.reason')])->validate();
                $reason = $data['reason'];
                $job->transitionTo(JobStatus::Draft);
            }
            (new JobReview)->forceFill([
                'job_id' => $job->id, 'reviewed_by' => $actor->id, 'decision' => $decision,
                'reason' => $reason, 'reviewed_at' => now(),
            ])->save();

            // Only generic text is stored in notifications. The linked record rechecks company access.
            $notification = Notification::make()->title(__('ui.review.notification_'.$decision))
                ->body(__('ui.review.notification_body'))
                ->actions([Action::make('view')->label(__('ui.jobs.preview'))->url(url('/app/tin-tuyen-dung/'.$job->id))->markAsRead()]);
            $recipients = $job->company->staff()->where('role', Role::Staff)->where('status', UserStatus::Active)->get();
            foreach ($recipients as $recipient) {
                // Store synchronously in this transaction: no SMTP or queue worker is required.
                $recipient->notifyNow($notification->toDatabase());
            }

            return $job;
        });
    }
}

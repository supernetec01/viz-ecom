<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Jobs\QueueApplicationSummaries;
use App\Jobs\SendApplicationSummary;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

class ApplicationSummaryQueue
{
    public function record(int $jobId, int $companyId): void
    {
        DB::table('application_mail_totals')->insertOrIgnore(['job_id' => $jobId, 'company_id' => $companyId,
            'total' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('application_mail_totals')->where('job_id', $jobId)->increment('total', 1, ['updated_at' => now()]);
        DB::afterCommit(function () use ($jobId, $companyId): void {
            try {
                $this->push(new QueueApplicationSummaries($jobId, $companyId));
            } catch (Throwable) {
                Log::warning('application_mail', ['job_id' => $jobId, 'error_code' => 'enqueue_failed']);
            }
        });
    }

    public function push(object $job, int $delay = 0): void
    {
        if ((config('queue.connections.database.connection') ?: config('database.default')) !== config('database.default')) {
            throw new RuntimeException('Application mail queue must use the application database.');
        }
        Queue::connection('database')->laterOn('application-mail', $delay, $job);
    }

    public function fanOut(int $jobId, int $companyId): void
    {
        $company = Company::find($companyId);
        if (! $company || ! DB::table('application_mail_totals')->where('job_id', $jobId)->where('company_id', $companyId)->exists()) {
            return;
        }
        foreach ($company->employers()->where('role', Role::Employer->value)->where('status', UserStatus::Active->value)->pluck('users.id') as $userId) {
            $this->push(new SendApplicationSummary($jobId, $companyId, $userId));
        }
    }
}

<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Mail\ApplicationSummaryMail;
use App\Models\Company;
use App\Models\Job;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendApplicationSummaryMail
{
    public function send(int $jobId, int $companyId, int $userId): ?int
    {
        $lock = Cache::lock('application-mail:'.$jobId.':'.$userId, 120);
        if (! $lock->get()) {
            return 30;
        }
        try {
            // Resolve recipient and role/company membership immediately before delivery.
            $company = Company::find($companyId);
            $job = Job::where('company_id', $companyId)->find($jobId);
            $recipient = $company?->employers()->whereKey($userId)->where('role', Role::Employer->value)->where('status', UserStatus::Active->value)->first();
            if (! $company || ! $job || ! $recipient) {
                return null;
            }
            $claim = DB::transaction(function () use ($jobId, $companyId, $userId): array {
                DB::table('application_mail_receipts')->insertOrIgnore(['job_id' => $jobId, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
                $receipt = DB::table('application_mail_receipts')->where('job_id', $jobId)->where('user_id', $userId)->lockForUpdate()->first();
                $total = (int) DB::table('application_mail_totals')->where('job_id', $jobId)->where('company_id', $companyId)->value('total');
                if ($total <= $receipt->notified_total) {
                    return [];
                }
                $wait = $receipt->attempted_at ? Carbon::parse($receipt->attempted_at)->addHour()->timestamp - now()->timestamp : 0;
                if ($wait > 0) {
                    return ['delay' => $wait];
                }
                // Durable before SMTP: an ambiguous delivery is never automatically replayed.
                DB::table('application_mail_receipts')->where('id', $receipt->id)->update(['notified_total' => $total,
                    'attempted_at' => now(), 'status' => 'sending', 'error_code' => null, 'updated_at' => now()]);

                return ['id' => $receipt->id, 'count' => $total - $receipt->notified_total];
            });
            if (! $claim || isset($claim['delay'])) {
                return $claim['delay'] ?? null;
            }
            try {
                Mail::to($recipient->email)->send(new ApplicationSummaryMail($company->name, $job->title, $claim['count']));
                DB::table('application_mail_receipts')->where('id', $claim['id'])->update(['status' => 'sent', 'updated_at' => now()]);
            } catch (Throwable) {
                Log::warning('application_mail', ['job_id' => $jobId, 'error_code' => 'delivery_uncertain']);
                // If this update fails too, durable `sending` still prevents the same batch repeating.
                try {
                    DB::table('application_mail_receipts')->where('id', $claim['id'])->update(['status' => 'uncertain', 'error_code' => 'delivery_uncertain', 'updated_at' => now()]);
                } catch (Throwable) { /* A later run still sees the advanced watermark. */
                }
            }

            return null;
        } finally {
            $lock->release();
        }
    }
}

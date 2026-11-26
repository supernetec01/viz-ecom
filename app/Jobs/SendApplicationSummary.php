<?php

namespace App\Jobs;

use App\Services\ApplicationSummaryQueue;
use App\Services\SendApplicationSummaryMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendApplicationSummary implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout = 60;

    public function __construct(public int $jobId, public int $companyId, public int $userId, public int $preSendFailures = 0)
    {
        $this->tries = max(1, 3 - $preSendFailures);
        $this->onConnection('database')->onQueue('application-mail');
    }

    public function handle(SendApplicationSummaryMail $sender): void
    {
        try {
            $delay = $sender->send($this->jobId, $this->companyId, $this->userId);
            if ($delay !== null) {
                $this->release($delay);
            }
        } catch (Throwable) {
            // Only pre-transport errors escape the sender. No raw exception/recipient/PII.
            Log::warning('application_mail', ['job_id' => $this->jobId, 'error_code' => 'pre_send_failed']);
            $this->preSendFailures += $this->attempts();
            if ($this->preSendFailures < 3) {
                try {
                    app(ApplicationSummaryQueue::class)->push(
                        new self($this->jobId, $this->companyId, $this->userId, $this->preSendFailures), [60, 300][$this->preSendFailures - 1]);
                } catch (Throwable) {
                    Log::warning('application_mail', ['job_id' => $this->jobId, 'error_code' => 'retry_enqueue_failed']);
                }
            }
        }
    }
}

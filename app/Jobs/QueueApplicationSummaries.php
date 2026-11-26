<?php

namespace App\Jobs;

use App\Services\ApplicationSummaryQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class QueueApplicationSummaries implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $jobId, public int $companyId)
    {
        $this->onConnection('database')->onQueue('application-mail');
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ApplicationSummaryQueue $queue): void
    {
        try {
            $queue->fanOut($this->jobId, $this->companyId);
        } catch (\Throwable) {
            throw new \RuntimeException('Application summary dispatch unavailable.');
        }
    }
}

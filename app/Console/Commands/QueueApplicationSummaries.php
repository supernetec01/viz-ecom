<?php

namespace App\Console\Commands;

use App\Jobs\QueueApplicationSummaries as DispatchSummary;
use App\Services\ApplicationSummaryQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class QueueApplicationSummaries extends Command
{
    protected $signature = 'application-summaries:dispatch';

    protected $description = 'Queue summary delivery checks from durable counters; no candidate data is read.';

    public function handle(ApplicationSummaryQueue $queue): int
    {
        $count = 0;
        DB::table('application_mail_totals')->where('total', '>', 0)->orderBy('job_id')->chunkById(200, function ($rows) use ($queue, &$count): void {
            foreach ($rows as $row) {
                $queue->push(new DispatchSummary($row->job_id, $row->company_id));
                $count++;
            }
        }, 'job_id');
        $this->info((string) $count);

        return self::SUCCESS;
    }
}

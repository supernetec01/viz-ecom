<?php

namespace App\Console\Commands;

use App\Exceptions\CandidateCleanupFailed;
use App\Services\CleanCandidateFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CleanupCandidateFiles extends Command
{
    protected $signature = 'candidate-files:cleanup {--force} {--json}';

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(__('files.cleanup_description'));
    }

    public function handle(CleanCandidateFiles $cleaner): int
    {
        $run = (string) Str::uuid();
        try {
            $report = $cleaner->run((bool) $this->option('force'));
        } catch (Throwable $exception) {
            $partial = $exception instanceof CandidateCleanupFailed ? array_diff_key($exception->report, array_flip(['items', 'unattached_metadata_ids'])) : ['scanned' => 0, 'deleted' => 0, 'skipped' => []];
            $summary = [...$partial, 'run' => $run, 'error' => 'inspection_or_database_failed'];
            Log::warning('candidate_cleanup_failed', $summary);
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
        $summary = array_diff_key($report, array_flip(['items', 'unattached_metadata_ids']));
        $summary['run'] = $run;
        if ($report['safety_stops'] !== []) {
            Log::warning('candidate_cleanup_stopped', $summary);
        } else {
            Log::info('candidate_cleanup_completed', $summary);
        }
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));
        } else {
            $this->line(__('files.cleanup_summary', $summary));
            foreach ($report['items'] as $item) {
                $this->line($item['root'].' '.$item['key'].' '.$item['modified_at']);
            }
            foreach ($report['unattached_metadata_ids'] as $id) {
                $this->line(__('files.unattached_metadata', ['id' => $id]));
            }
            if ($report['safety_stops'] !== []) {
                $this->warn(__('files.cleanup_stopped').': '.implode(', ', $report['safety_stops']));
            }
        }

        return $report['safety_stops'] === [] ? self::SUCCESS : self::FAILURE;
    }
}

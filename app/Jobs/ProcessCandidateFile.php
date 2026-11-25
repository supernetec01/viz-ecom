<?php

namespace App\Jobs;

use App\Services\ProcessStoredFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessCandidateFile implements ShouldQueue
{
    use Queueable;

    // Business attempts are persisted and capped at three; busy locks do not spend an attempt.
    public int $tries = 0;

    public int $timeout = 180;

    public function __construct(public int $fileId, public int $version = 0)
    {
        $this->onConnection('database')->onQueue('candidate-files')->beforeCommit();
    }

    public function handle(ProcessStoredFile $processor): void
    {
        $lock = Cache::lock('candidate-process:'.$this->fileId, 240);
        if (! $lock->get()) {
            $this->release(60);

            return;
        }
        try {
            $delay = $processor->process($this->fileId, $this->version);
            if ($delay !== null) {
                $this->release($delay);
            }
        } catch (Throwable) {
            Log::warning('candidate_file_processing', ['file_id' => $this->fileId, 'error_code' => 'processing_unavailable']);
            $this->release(60);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ProcessStoredFile::class)->markWorkerFailure($this->fileId, $this->version);
    }
}

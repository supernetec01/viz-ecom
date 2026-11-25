<?php

namespace App\Services;

use App\Jobs\ProcessCandidateFile;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

class EnqueueCandidateFile
{
    public function enqueue(int $fileId, int $version = 0): void
    {
        if ((config('queue.connections.database.connection') ?: config('database.default')) !== config('database.default')) {
            throw new RuntimeException('Candidate queue must share the application transaction.');
        }
        Queue::connection('database')->pushOn('candidate-files', new ProcessCandidateFile($fileId, $version));
    }
}

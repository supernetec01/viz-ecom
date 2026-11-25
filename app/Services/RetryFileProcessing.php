<?php

namespace App\Services;

use App\Events\FileProcessingRetryRequested;
use App\Exceptions\FileProcessingFailed;
use App\Models\CandidateFile;
use App\Models\User;
use App\Support\CandidateFilePaths;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RetryFileProcessing
{
    public function retry(User $actor, int $id): void
    {
        Gate::forUser($actor)->authorize('viewAny', CandidateFile::class);
        $lock = Cache::lock('candidate-process:'.$id, 240);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['file' => __('files.busy')]);
        }
        try {
            DB::transaction(function () use ($actor, $id): void {
                $file = CandidateFile::lockForUpdate()->findOrFail($id);
                Gate::forUser($actor)->authorize('retry', $file);
                try {
                    $path = CandidateFilePaths::original($file);
                    $same = hash_file('sha256', $path) === $file->scanned_sha256;
                } catch (FileProcessingFailed) {
                    $same = false;
                }
                $previewOnly = $file->scan_status === 'clean' && $file->preview_status === 'failed' && $same;
                $stage = $previewOnly ? 'preview' : 'scan';
                $file->forceFill(['processing_version' => $file->processing_version + 1, 'processing_attempts' => 0,
                    'preview_attempts' => 0, 'preview_status' => 'pending', 'preview_error_code' => null, 'preview_path' => null,
                    'review_required' => false, ...($previewOnly ? [] : ['scan_status' => 'pending', 'scan_attempts' => 0, 'scan_error_code' => null, 'scanned_sha256' => null])])->save();
                app(EnqueueCandidateFile::class)->enqueue($id, $file->processing_version);
                // Domain audit hook: T35 can persist this without introducing audit_logs early.
                event(new FileProcessingRetryRequested($actor->id, $id, $stage, now()->utc()->toIso8601String()));
            });
        } finally {
            $lock->release();
        }
    }
}

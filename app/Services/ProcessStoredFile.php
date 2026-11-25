<?php

namespace App\Services;

use App\Contracts\PreviewConverter;
use App\Contracts\VirusScanner;
use App\Exceptions\FileProcessingFailed;
use App\Exceptions\PdfInspectionFailed;
use App\Models\CandidateFile;
use App\Support\CandidateFilePaths;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessStoredFile
{
    public function __construct(private VirusScanner $scanner, private PreviewConverter $converter, private InspectCv $cv,
        private InspectPortfolio $portfolio, private InspectPdf $pdf) {}

    public function process(int $id, int $version): ?int
    {
        $file = CandidateFile::find($id);
        if (! $file || $file->processing_version !== $version || $file->scan_status === 'infected'
            || ($file->review_required && ($file->scan_status === 'error' || $file->preview_status === 'failed'))
            || ($file->scan_status === 'clean' && $file->preview_status === 'ready')) {
            return null;
        }
        if ($file->processing_attempts >= 3) {
            $this->terminal($file, $file->scan_status === 'clean' ? 'preview' : 'scan', 'worker_interrupted');

            return null;
        }
        $file->forceFill(['processing_attempts' => $file->processing_attempts + 1])->save();
        $stage = $file->scan_status === 'clean' ? 'preview' : 'scan';
        $leases = [];
        $temporary = null;
        try {
            $leases[] = CandidateFileLease::acquire('candidate:'.$file->path);
            $path = CandidateFilePaths::original($file);
            $hash = hash_file('sha256', $path);
            if (! is_string($hash)) {
                throw new FileProcessingFailed('file_missing');
            }
            if ($file->scan_status !== 'clean' || $file->scanned_sha256 !== $hash) {
                $stage = 'scan';
                $file->forceFill(['scan_status' => 'pending', 'preview_status' => 'pending', 'preview_path' => null,
                    'scan_attempts' => $file->scan_attempts + 1])->save();
                $upload = new UploadedFile($path, 'input.'.pathinfo($path, PATHINFO_EXTENSION), null, null, true);
                $checked = str_ends_with($file->path, '.docx') ? $this->cv->inspect($upload) : $this->portfolio->inspect($upload);
                $this->scanner->scan($path);
                $this->unchanged($path, $hash);
                $file->forceFill(['scan_status' => 'clean', 'scan_error_code' => null, 'sha256' => $hash,
                    'scanned_sha256' => $hash, 'size' => $checked['size'], 'mime_type' => $checked['mime_type'], 'scanned_at' => now()])->save();
            }
            $stage = 'preview';
            if (! str_ends_with($file->path, '.docx')) {
                $file->forceFill(['preview_status' => 'ready', 'preview_path' => $file->path, 'preview_error_code' => null, 'review_required' => false])->save();

                return null;
            }
            $file->forceFill(['preview_status' => 'pending', 'preview_attempts' => $file->preview_attempts + 1])->save();
            $bytes = $this->converter->convert($path);
            if (strlen($bytes) < 1 || strlen($bytes) > InspectCv::MAX_BYTES) {
                throw new FileProcessingFailed('preview_output_limit');
            }
            $relative = 'previews/'.$file->id.'/'.$hash.'.pdf';
            $temporary = 'previews/'.$file->id.'/'.Str::uuid().'.tmp';
            $leases[] = CandidateFileLease::acquire('candidate:'.$relative);
            $leases[] = CandidateFileLease::acquire('candidate:'.$temporary);
            $disk = Storage::disk('candidate-files');
            $disk->put($temporary, $bytes, ['visibility' => 'private']);
            $this->pdf->inspect($disk->path($temporary));
            $this->scanner->scan($disk->path($temporary));
            $this->unchanged($path, $hash);
            if (! $disk->move($temporary, $relative)) {
                throw new FileProcessingFailed('preview_write_failed', true);
            }
            $temporary = null;
            $file->forceFill(['preview_path' => $relative, 'preview_status' => 'ready', 'preview_error_code' => null, 'review_required' => false])->save();

            return null;
        } catch (Throwable $exception) {
            $failure = $exception instanceof FileProcessingFailed ? $exception
                : ($exception instanceof PdfInspectionFailed && in_array($exception->reason, ['timeout', 'unavailable', 'isolation', 'cleanup'], true)
                    ? new FileProcessingFailed('pdf_checker_unavailable', true)
                    : ($exception instanceof ValidationException || $exception instanceof PdfInspectionFailed
                    ? new FileProcessingFailed($stage === 'scan' ? 'invalid_document' : 'preview_validation_failed')
                    : new FileProcessingFailed('processing_unavailable', true)));

            if ($failure->reason === 'infected') {
                $stage = 'scan';
            }

            if ($failure->reason === 'file_changed') {
                $stage = 'scan';
                $file->forceFill(['scan_status' => 'pending', 'scanned_sha256' => null, 'preview_status' => 'pending', 'preview_path' => null])->save();
            }

            return $this->failure($file, $stage, $failure);
        } finally {
            if ($temporary !== null) {
                try {
                    Storage::disk('candidate-files')->delete($temporary);
                } catch (Throwable) {
                    Log::warning('candidate_file_processing', ['file_id' => $id, 'error_code' => 'temporary_cleanup_failed']);
                }
            }
            foreach ($leases as $lease) {
                $lease?->release();
            }
        }
    }

    public function markWorkerFailure(int $id, int $version): void
    {
        $file = CandidateFile::find($id);
        if ($file && $file->processing_version === $version && $file->preview_status !== 'ready' && $file->scan_status !== 'infected') {
            $this->terminal($file, $file->scan_status === 'clean' ? 'preview' : 'scan', 'worker_failed');
        }
    }

    private function failure(CandidateFile $file, string $stage, FileProcessingFailed $failure): ?int
    {
        Log::warning('candidate_file_processing', ['file_id' => $file->id, 'error_code' => $failure->reason]);
        if ($failure->transient && $file->processing_attempts < 3) {
            $file->forceFill([$stage.'_error_code' => $failure->reason])->save();

            return [60, 300][$file->processing_attempts - 1];
        }
        $this->terminal($file, $stage, $failure->reason);

        return null;
    }

    private function terminal(CandidateFile $file, string $stage, string $code): void
    {
        $file->forceFill(['review_required' => true, $stage.'_error_code' => $code,
            ...($stage === 'scan' ? ['scan_status' => $code === 'infected' ? 'infected' : 'error', 'preview_status' => 'failed', 'preview_path' => null]
                : ['preview_status' => 'failed', 'preview_path' => null])])->save();
    }

    private function unchanged(string $path, string $hash): void
    {
        if (hash_file('sha256', $path) !== $hash) {
            throw new FileProcessingFailed('file_changed', true);
        }
    }
}

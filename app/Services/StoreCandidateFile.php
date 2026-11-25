<?php

namespace App\Services;

use App\Models\Job;
use App\Support\StoredCandidateFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class StoreCandidateFile
{
    public function __construct(private InspectCv $inspector, private InspectPortfolio $portfolio) {}

    public function storeCv(UploadedFile $upload, Job $job, string $applicationId): StoredCandidateFile
    {
        if (! $job->exists || $job->id < 1 || $job->company_id < 1 || ! Str::isUlid($applicationId)) {
            throw new RuntimeException('Invalid candidate file context.');
        }
        $checked = $this->inspector->inspect($upload);

        return $this->store($upload, $job, $applicationId, $checked, 'cv', 'cv');
    }

    public function storePortfolio(UploadedFile $upload, Job $job, string $applicationId): StoredCandidateFile
    {
        if (! $job->exists || $job->id < 1 || $job->company_id < 1 || ! Str::isUlid($applicationId)) {
            throw new RuntimeException('Invalid candidate file context.');
        }

        return $this->store($upload, $job, $applicationId, $this->portfolio->inspect($upload), 'portfolio', 'portfolio_file');
    }

    private function store(UploadedFile $upload, Job $job, string $applicationId, array $checked, string $kind, string $field): StoredCandidateFile
    {
        $path = $kind.'/'.$job->company_id.'/'.$job->id.'/'.$applicationId.'/'.Str::uuid().'.'.$checked['extension'];
        $lease = CandidateFileLease::acquire('candidate:'.$path);
        $stream = fopen($upload->getPathname(), 'rb');
        if ($stream === false) {
            throw ValidationException::withMessages([$field => __('applications.file_upload_failed')]);
        }
        try {
            $written = Storage::disk('candidate-files')->put($path, $stream, ['visibility' => 'private']);
            if (! $written) {
                throw new RuntimeException('Candidate file write failed.');
            }
        } catch (Throwable) {
            $this->discardPath($path);
            throw ValidationException::withMessages([$field => __('applications.file_upload_failed')]);
        } finally {
            fclose($stream);
        }

        return new StoredCandidateFile([
            'path' => $path, 'original_name' => mb_substr(basename(str_replace('\\', '/', $upload->getClientOriginalName())), 0, 255),
            'mime_type' => $checked['mime_type'], 'size' => $checked['size'], 'sha256' => $checked['sha256'], 'scan_status' => 'pending',
        ], $lease);
    }

    public function discard(StoredCandidateFile $file): void
    {
        $this->discardPath($file->path());
    }

    private function discardPath(string $path): void
    {
        $context = '[1-9][0-9]*/[1-9][0-9]*/[0-7][0-9A-HJKMNP-TV-Z]{25}/[0-9a-f-]{36}';
        if (! preg_match('~\A(?:cv/'.$context.'\.(?:docx|pdf)|portfolio/'.$context.'\.(?:pdf|jpg|png))\z~D', $path)) {
            throw new RuntimeException('Invalid candidate cleanup path.');
        }
        if (! Storage::disk('candidate-files')->delete($path)) {
            throw new RuntimeException('Candidate file cleanup failed.');
        }
    }
}

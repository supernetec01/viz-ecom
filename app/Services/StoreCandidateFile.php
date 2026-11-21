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
    public function __construct(private InspectCv $inspector) {}

    public function storeCv(UploadedFile $upload, Job $job, string $applicationId): StoredCandidateFile
    {
        if (! $job->exists || $job->id < 1 || $job->company_id < 1 || ! Str::isUlid($applicationId)) {
            throw new RuntimeException('Invalid candidate file context.');
        }
        $checked = $this->inspector->inspect($upload);
        $path = 'cv/'.$job->company_id.'/'.$job->id.'/'.$applicationId.'/'.Str::uuid().'.'.$checked['extension'];
        $stream = fopen($upload->getPathname(), 'rb');
        if ($stream === false) {
            throw ValidationException::withMessages(['cv' => __('applications.upload_failed')]);
        }
        try {
            $written = Storage::disk('candidate-files')->put($path, $stream, ['visibility' => 'private']);
            if (! $written) {
                throw new RuntimeException('Candidate file write failed.');
            }
        } catch (Throwable) {
            $this->discardPath($path);
            throw ValidationException::withMessages(['cv' => __('applications.upload_failed')]);
        } finally {
            fclose($stream);
        }

        return new StoredCandidateFile([
            'path' => $path, 'original_name' => mb_substr(basename(str_replace('\\', '/', $upload->getClientOriginalName())), 0, 255),
            'mime_type' => $checked['mime_type'], 'size' => $checked['size'], 'sha256' => $checked['sha256'], 'scan_status' => 'pending',
        ]);
    }

    public function discard(StoredCandidateFile $file): void
    {
        $this->discardPath($file->path());
    }

    private function discardPath(string $path): void
    {
        if (! preg_match('~\Acv/[1-9][0-9]*/[1-9][0-9]*/[0-7][0-9A-HJKMNP-TV-Z]{25}/[0-9a-f-]{36}\.(docx|pdf)\z~D', $path)) {
            throw new RuntimeException('Invalid candidate cleanup path.');
        }
        if (! Storage::disk('candidate-files')->delete($path)) {
            throw new RuntimeException('Candidate file cleanup failed.');
        }
    }
}

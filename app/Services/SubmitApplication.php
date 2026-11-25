<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Job;
use App\Policies\ApplicationPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SubmitApplication
{
    public function __construct(private StoreCandidateFile $storage, private ApplicationPolicy $policy) {}

    public function submit(int $jobId, array $data, ?UploadedFile $cv, ?string $source, ?UploadedFile $portfolio = null): void
    {
        $stored = [];
        $bodyCompleted = false;
        try {
            DB::transaction(function () use ($jobId, $data, $cv, $source, $portfolio, &$stored, &$bodyCompleted): void {
                $job = Job::publiclyViewable()->lockForUpdate()->findOrFail($jobId);
                $company = Company::whereKey($job->company_id)->sharedLock()->firstOrFail();
                abort_unless($company->verified_at !== null, 404);
                $this->policy->assertMaySubmit($job, $data['phone']);
                $id = (string) Str::ulid();
                $fileIds = ['cv' => null, 'portfolio' => null];
                foreach (['cv' => $cv, 'portfolio' => $portfolio] as $kind => $upload) {
                    if ($upload) {
                        $file = $kind === 'cv' ? $this->storage->storeCv($upload, $job, $id) : $this->storage->storePortfolio($upload, $job, $id);
                        $stored[] = $file;
                        $fileIds[$kind] = DB::table('files')->insertGetId([...$file->metadata(), 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                // The clock may cross expires_at while inspecting a PDF, even with the row locked.
                $this->policy->assertMaySubmit($job);
                DB::table('applications')->insert([
                    'id' => $id, 'job_id' => $job->id, 'company_id' => $job->company_id,
                    'submission_hash' => hash('sha256', $data['submission_token']),
                    'full_name' => $data['full_name'], 'phone_normalized' => $data['phone'], 'email' => $data['email'] ?? null,
                    'cv_file_id' => $fileIds['cv'], 'portfolio_url' => $data['portfolio_url'] ?? null, 'portfolio_file_id' => $fileIds['portfolio'],
                    'consent_given' => true, 'consented_at' => now(), 'submitted_at' => now(), 'source' => $source,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach (array_filter($fileIds) as $fileId) {
                    app(EnqueueCandidateFile::class)->enqueue($fileId);
                }
                $bodyCompleted = true;
            });
        } catch (Throwable $exception) {
            if ($bodyCompleted) {
                // Commit may have succeeded before its acknowledgement/callback failed.
                // Preserve bytes; the later orphan check must consult committed metadata.
                throw ValidationException::withMessages(['application' => __('applications.save_failed')]);
            }
            $cleanupFailed = false;
            foreach ($stored as $file) {
                try {
                    $this->storage->discard($file);
                } catch (Throwable) {
                    $cleanupFailed = true;
                }
            }
            if ($cleanupFailed) {
                throw ValidationException::withMessages(['application' => __('applications.save_failed')]);
            }
            if ($exception instanceof ValidationException || $exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            if ($exception instanceof QueryException && $exception->getCode() === '23505'
                && str_contains($exception->getMessage(), 'applications_active_job_phone_unique')) {
                throw ValidationException::withMessages(['phone' => __('applications.duplicate')]);
            }
            if ($exception instanceof QueryException && $exception->getCode() === '23505'
                && str_contains($exception->getMessage(), 'applications_submission_hash_unique')) {
                throw ValidationException::withMessages(['submission_token' => __('applications.form_expired')]);
            }
            // No QueryException/previous exception escapes into logs with candidate bindings.
            throw ValidationException::withMessages(['application' => __('applications.save_failed')]);
        }
    }
}

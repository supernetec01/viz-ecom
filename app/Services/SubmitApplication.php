<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Job;
use App\Policies\ApplicationPolicy;
use App\Support\StoredCandidateFile;
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

    public function submit(int $jobId, array $data, ?UploadedFile $cv, ?string $source): void
    {
        $stored = null;
        try {
            DB::transaction(function () use ($jobId, $data, $cv, $source, &$stored): void {
                $job = Job::publiclyViewable()->lockForUpdate()->findOrFail($jobId);
                $company = Company::whereKey($job->company_id)->sharedLock()->firstOrFail();
                abort_unless($company->verified_at !== null, 404);
                $this->policy->assertMaySubmit($job, $data['phone']);
                $id = (string) Str::ulid();
                $stored = $cv ? $this->storage->storeCv($cv, $job, $id) : null;
                $fileId = $stored ? DB::table('files')->insertGetId([...$stored->metadata(), 'created_at' => now(), 'updated_at' => now()]) : null;
                // The clock may cross expires_at while inspecting a PDF, even with the row locked.
                $this->policy->assertMaySubmit($job);
                DB::table('applications')->insert([
                    'id' => $id, 'job_id' => $job->id, 'company_id' => $job->company_id,
                    'submission_hash' => hash('sha256', $data['submission_token']),
                    'full_name' => $data['full_name'], 'phone_normalized' => $data['phone'], 'email' => $data['email'] ?? null,
                    'cv_file_id' => $fileId, 'portfolio_url' => $data['portfolio_url'] ?? null,
                    'consent_given' => true, 'consented_at' => now(), 'submitted_at' => now(), 'source' => $source,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            if ($stored instanceof StoredCandidateFile) {
                $this->storage->discard($stored);
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

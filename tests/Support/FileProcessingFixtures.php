<?php

use App\Models\CandidateFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/CvFixtures.php';
require_once __DIR__.'/PortfolioFixtures.php';

function processingFile(string $type = 'docx', ?string $bytes = null): CandidateFile
{
    $job = publishManagedJob(managedJob(jobManager()));
    $applicationId = (string) Str::ulid();
    $path = ($type === 'docx' ? 'cv' : 'portfolio').'/'.$job->company_id.'/'.$job->id.'/'.$applicationId.'/'.Str::uuid().'.'.$type;
    $upload = $type === 'docx' ? docxUpload() : null;
    $bytes ??= $upload ? file_get_contents($upload->getPathname()) : portfolioPngBytes();
    Storage::disk('candidate-files')->put($path, $bytes, ['visibility' => 'private']);
    $id = DB::table('files')->insertGetId(['path' => $path, 'original_name' => 'PRIVATE_ORIGINAL_NAME.'.$type,
        'mime_type' => $type === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'image/png',
        'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('applications')->insert(['id' => $applicationId, 'job_id' => $job->id, 'company_id' => $job->company_id,
        'full_name' => 'PRIVATE_CANDIDATE_NAME', 'phone_normalized' => '0912345678', 'email' => 'private@example.test',
        $type === 'docx' ? 'cv_file_id' : 'portfolio_file_id' => $id, 'consent_given' => true, 'consented_at' => now(), 'submitted_at' => now()]);

    return CandidateFile::findOrFail($id);
}

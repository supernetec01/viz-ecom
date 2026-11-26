<?php

use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function applicationAccessFixture(): array
{
    $admin = jobManager(Role::Admin);
    $job = publishManagedJob(managedJob($admin));
    $employer = jobManager(Role::Employer);
    $job->company->employers()->attach($employer);
    $fileId = DB::table('files')->insertGetId(['path' => 'PRIVATE_CV_PATH', 'original_name' => 'PRIVATE_CV_NAME.pdf',
        'mime_type' => 'application/pdf', 'size' => 5, 'sha256' => hash('sha256', 'bytes'), 'scan_status' => 'clean', 'preview_status' => 'ready',
        'preview_path' => 'PRIVATE_PREVIEW_PATH', 'created_at' => now(), 'updated_at' => now()]);
    $applicationId = (string) Str::ulid();
    DB::table('applications')->insert(['id' => $applicationId, 'job_id' => $job->id, 'company_id' => $job->company_id,
        'full_name' => 'CANDIDATE_VISIBLE_NAME', 'phone_normalized' => '0912345678', 'email' => 'PRIVATE_CONTACT@example.test',
        'cv_file_id' => $fileId, 'portfolio_url' => 'https://private-portfolio.example.test',
        'consent_given' => true, 'consented_at' => now()->subDays(3), 'submitted_at' => now()->subDays(3),
        'internal_notes' => 'PRIVATE_INTERNAL_NOTES', 'created_at' => now(), 'updated_at' => now()]);

    return compact('admin', 'job', 'employer', 'fileId', 'applicationId');
}

function applicationAccessGrant(array $fixture, array $overrides = []): AccessGrant
{
    $order = new Order;
    $order->forceFill(['company_id' => $fixture['job']->company_id, 'type' => 'access_grant', 'amount' => 100000, 'status' => 'paid',
        'method' => 'bank_transfer', 'confirmed_by' => $fixture['admin']->id, 'confirmed_at' => now()])->save();
    $grant = new AccessGrant;
    $grant->forceFill(array_replace(['company_id' => $fixture['job']->company_id, 'job_id' => $fixture['job']->id,
        'order_id' => $order->id, 'created_by' => $fixture['admin']->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()], $overrides))->save();

    return $grant;
}

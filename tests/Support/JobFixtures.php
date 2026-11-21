<?php

use App\Enums\JobStatus;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Job;
use App\Models\JobCategory;
use App\Models\Location;
use App\Models\User;

function jobManager(Role $role = Role::Staff): User
{
    return User::factory()->create(['role' => $role, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
}

function managedJob(User $staff): Job
{
    $company = Company::create(['name' => 'Managed company']);
    $company->forceFill(['verified_at' => now(), 'verified_by' => $staff->id])->save();
    $company->staff()->attach($staff);
    $job = new Job([
        'company_id' => $company->id,
        'category_id' => JobCategory::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales'])->id,
        'location_id' => Location::firstOrCreate(['code' => '01'], ['name' => 'Hà Nội'])->id,
        'ward_name' => 'Phường thử nghiệm', 'work_address' => 'Số 12, đường thử nghiệm',
        'title' => 'Nhân viên kinh doanh', 'description' => '<p>Mô tả</p>', 'requirements' => '<p>Yêu cầu</p>', 'benefits' => '<p>Quyền lợi</p>',
    ]);
    $job->created_by = $staff->id;
    $job->save();

    return $job;
}

function publishManagedJob(Job $job): Job
{
    $job->expires_at = now()->addDays(7);
    $job->transitionTo(JobStatus::Published);

    return $job;
}

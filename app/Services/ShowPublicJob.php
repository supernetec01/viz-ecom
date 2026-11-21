<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Models\Job;
use Illuminate\Database\Eloquent\Collection;

class ShowPublicJob
{
    public function find(string $id): Job
    {
        abort_if(strlen($id) > 19 || (strlen($id) === 19 && strcmp($id, (string) PHP_INT_MAX) > 0), 404);
        $query = Job::publiclyViewable()->select([
            'id', 'slug', 'title', 'status', 'company_id', 'category_id', 'location_id', 'ward_name', 'work_address',
        ]);
        // Mask closed content in the database result, not just in the Blade template.
        foreach (['description', 'requirements', 'benefits', 'salary_min', 'salary_max', 'negotiable', 'experience_years', 'vacancies', 'employment_type', 'published_at', 'expires_at'] as $column) {
            $query->selectRaw("CASE WHEN status = 'closed' THEN NULL ELSE {$column} END AS {$column}");
        }

        return $query->with(['company:id,name', 'category:id,name', 'location:id,name'])->findOrFail($id);
    }

    public function state(Job $job): string
    {
        if ($job->status === JobStatus::Closed) {
            return 'closed';
        }
        if ($job->canReceiveApplications()) {
            return 'published';
        }
        if ($job->status === JobStatus::Expired || ! $job->expires_at || ! $job->expires_at->isFuture()) {
            return 'expired';
        }

        return 'paused';
    }

    /** @return Collection<int, Job> */
    public function similar(Job $job): Collection
    {
        return Job::publiclyVisible()->whereKeyNot($job->id)->where('category_id', $job->category_id)
            ->select(['id', 'slug', 'title', 'company_id', 'location_id', 'ward_name', 'work_address'])
            ->with(['company:id,name', 'location:id,name'])
            ->orderByRaw('CASE WHEN location_id = ? THEN 0 ELSE 1 END', [$job->location_id])
            ->orderByDesc('published_at')->orderByDesc('id')->limit(3)->get();
    }
}

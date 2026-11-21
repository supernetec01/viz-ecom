<?php

namespace App\Services;

use App\Models\Job;
use Illuminate\Pagination\LengthAwarePaginator;

class ListPublicJobs
{
    public const PER_PAGE = 12;

    /** @return LengthAwarePaginator<int, Job> */
    public function paginate(): LengthAwarePaginator
    {
        // A public projection: do not fetch reviewer, creator, contact content or scan metadata.
        return Job::publiclyVisible()->select([
            'id', 'title', 'company_id', 'category_id', 'location_id', 'ward_name', 'work_address',
            'salary_min', 'salary_max', 'negotiable', 'experience_years', 'vacancies', 'employment_type', 'published_at', 'expires_at',
        ])->with(['company:id,name,type', 'category:id,name', 'location:id,name'])
            ->orderByDesc('published_at')->orderByDesc('id')->paginate(self::PER_PAGE);
    }
}

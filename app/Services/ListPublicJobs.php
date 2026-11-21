<?php

namespace App\Services;

use App\Models\Job;
use Illuminate\Pagination\LengthAwarePaginator;

class ListPublicJobs
{
    public const PER_PAGE = 12;

    /** @return LengthAwarePaginator<int, Job> */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = Job::publiclyVisible();
        foreach (['dia_diem' => 'location_id', 'vi_tri' => 'category_id'] as $key => $column) {
            if (isset($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }
        if (isset($filters['luong_tu'])) {
            $query->where('negotiable', false)->where('salary_min', '>=', $filters['luong_tu']);
        }
        if (isset($filters['kinh_nghiem'])) {
            $query->where('experience_years', '<=', $filters['kinh_nghiem']);
        }
        if (isset($filters['loai_cong_ty'])) {
            $query->whereHas('company', fn ($company) => $company->where('type', $filters['loai_cong_ty']));
        }

        // A public projection: do not fetch reviewer, creator, contact content or scan metadata.
        return $query->select([
            'id', 'title', 'company_id', 'category_id', 'location_id', 'ward_name', 'work_address',
            'salary_min', 'salary_max', 'negotiable', 'experience_years', 'vacancies', 'employment_type', 'published_at', 'expires_at',
        ])->with(['company:id,name,type', 'category:id,name', 'location:id,name'])
            ->orderByDesc('published_at')->orderByDesc('id')->paginate(self::PER_PAGE)->appends($filters);
    }
}

<?php

namespace App\Support;

use App\Enums\CompanyType;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PublicJobFilters
{
    public const SALARIES = [5000000, 10000000, 15000000, 20000000, 30000000];

    public const EXPERIENCE = [0, 1, 2, 3, 5, 10];

    public static function validator(array $input): ValidatorContract
    {
        return Validator::make($input, [
            'dia_diem' => ['nullable', 'integer', Rule::exists('locations', 'id')->whereNull('parent_id')],
            'vi_tri' => ['nullable', 'integer', 'exists:job_categories,id'],
            'luong_tu' => ['nullable', 'integer', Rule::in(self::SALARIES)],
            'kinh_nghiem' => ['nullable', 'integer', Rule::in(self::EXPERIENCE)],
            'loai_cong_ty' => ['nullable', 'string', Rule::enum(CompanyType::class)],
        ], [], [
            'dia_diem' => __('ui.jobs.location'), 'vi_tri' => __('ui.jobs.category'),
            'luong_tu' => __('public.filter_salary'), 'kinh_nghiem' => __('public.filter_experience'),
            'loai_cong_ty' => __('ui.companies.type'),
        ]);
    }

    public static function normalized(array $validated): array
    {
        return array_filter($validated, fn ($value): bool => $value !== null && $value !== '');
    }
}

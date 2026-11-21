<?php

namespace App\Support;

use App\Enums\EmploymentType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class JobData
{
    // Shared by the model and the job form introduced in T11. Amounts are integer VND.
    public static function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')->whereNull('deleted_at')->whereNotNull('verified_at')->whereNotNull('verified_by')],
            'category_id' => ['required', 'integer', 'exists:job_categories,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:50000'],
            'requirements' => ['required', 'string', 'max:50000'],
            'benefits' => ['required', 'string', 'max:50000'],
            'salary_min' => ['nullable', 'integer', 'min:0', 'max:9223372036854775807'],
            'salary_max' => ['nullable', 'integer', 'min:0', 'max:9223372036854775807'],
            'negotiable' => ['required', 'boolean'],
            'experience_years' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'vacancies' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'expires_at' => ['nullable', 'date'],
        ];
    }

    public static function validate(array $data): array
    {
        $validator = Validator::make($data, self::rules());
        $validator->after(function ($validator) use ($data): void {
            if (is_numeric($data['salary_min'] ?? null) && is_numeric($data['salary_max'] ?? null) && $data['salary_min'] > $data['salary_max']) {
                $validator->errors()->add('salary_max', __('ui.jobs.salary_range'));
            }
        });

        return $validator->validate();
    }
}

<?php

namespace App\Support;

use App\Enums\EmploymentType;
use App\Models\Job;
use Illuminate\Support\Str;

class PublicSeo
{
    public static function url(string $route, array $parameters = []): string
    {
        return rtrim(config('app.url'), '/').route($route, $parameters, false);
    }

    public static function text(?string $value, int $limit = 200): string
    {
        return Str::limit(Str::squish(html_entity_decode(strip_tags($value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')), $limit);
    }

    public static function jobDescription(Job $job, string $state): string
    {
        $parts = [$job->company->name, $job->workplaceAddress() ?? $job->location->name];
        if ($state !== 'closed') {
            $parts[] = JobPresentation::salary($job);
        }
        $parts[] = __('public.detail_states.'.$state);

        return implode(' · ', $parts);
    }

    public static function jobPosting(Job $job): ?array
    {
        if (! config('seo.job_posting_enabled') || ! $job->canReceiveApplications() || ! $job->workplaceAddress()) {
            return null;
        }

        return [
            '@context' => 'https://schema.org', '@type' => 'JobPosting',
            'title' => $job->title,
            'description' => implode("\n", array_map(fn (string $field): string => '<p>'.e(__('ui.jobs.'.$field)).'</p>'.JobHtml::clean($job->{$field}), ['description', 'requirements', 'benefits'])),
            'identifier' => ['@type' => 'PropertyValue', 'name' => config('app.name'), 'value' => (string) $job->id],
            'url' => self::url('jobs.show', ['slug' => $job->slug, 'id' => $job->id]),
            'datePosted' => $job->published_at->toIso8601String(), 'validThrough' => $job->expires_at->toIso8601String(),
            'employmentType' => $job->employment_type === EmploymentType::FullTime ? 'FULL_TIME' : 'PART_TIME',
            'hiringOrganization' => ['@type' => 'Organization', 'name' => $job->company->name, 'sameAs' => self::url('companies.show', ['id' => $job->company_id])],
            'jobLocation' => ['@type' => 'Place', 'address' => [
                '@type' => 'PostalAddress', 'streetAddress' => $job->work_address, 'addressLocality' => $job->ward_name,
                'addressRegion' => $job->location->name, 'addressCountry' => 'VN',
            ]],
            // Salary period is not stored yet; do not invent a baseSalary unit.
        ];
    }
}

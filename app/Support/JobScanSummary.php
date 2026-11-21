<?php

namespace App\Support;

use App\Models\Job;

class JobScanSummary
{
    public static function for(Job $job): string
    {
        return collect($job->content_scan_reasons ?? [])->map(function (array $finding): string {
            $field = __('ui.jobs.'.($finding['field'] ?? 'title'));
            $rule = __('ui.review.rules.'.($finding['rule'] ?? 'external_link'));
            $url = ($finding['rule'] ?? '') === 'external_link' ? ' — '.($finding['value'] ?? '') : '';

            return $field.': '.$rule.$url;
        })->implode("\n") ?: __('ui.review.no_scan_reason');
    }
}

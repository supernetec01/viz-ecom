<?php

namespace App\Support;

use App\Models\Job;

class JobPresentation
{
    public static function salary(Job $job): string
    {
        if ($job->negotiable) {
            return __('public.salary_negotiable');
        }
        $min = $job->salary_min;
        $max = $job->salary_max;
        $amount = fn (int $value): string => number_format($value, 0, ',', '.');

        return match (true) {
            $min === null && $max === null => __('public.salary_unknown'),
            $max === null => __('public.salary_from', ['amount' => $amount($min)]),
            $min === null => __('public.salary_to', ['amount' => $amount($max)]),
            $min === $max => $amount($min).' ₫',
            default => $amount($min).' – '.$amount($max).' ₫',
        };
    }
}

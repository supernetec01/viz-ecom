<?php

use App\Models\Job;
use App\Support\JobPresentation;
use Tests\TestCase;

uses(TestCase::class);

it('formats salary without assuming missing amounts or a payment period', function (array $salary, string $expected) {
    expect(JobPresentation::salary(new Job($salary)))->toBe($expected);
})->with([
    [['negotiable' => true], 'Lương thỏa thuận'],
    [[], 'Lương chưa công bố'],
    [['salary_min' => 10000000], 'Từ 10.000.000 ₫'],
    [['salary_max' => 20000000], 'Đến 20.000.000 ₫'],
    [['salary_min' => 12000000, 'salary_max' => 12000000], '12.000.000 ₫'],
    [['salary_min' => 0, 'salary_max' => 20000000], '0 – 20.000.000 ₫'],
    [['salary_min' => 10000000, 'salary_max' => 20000000], '10.000.000 – 20.000.000 ₫'],
    [['salary_min' => PHP_INT_MAX, 'salary_max' => PHP_INT_MAX], '9.223.372.036.854.775.807 ₫'],
]);

<?php

use App\Models\JobCategory;
use App\Models\Location;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds 34 provinces and 8 categories idempotently', function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(ReferenceDataSeeder::class);
    expect(Location::count())->toBe(34)->and(JobCategory::count())->toBe(8);
    $this->assertDatabaseHas('locations', ['code' => '01', 'name' => 'Hà Nội']);
    $this->assertDatabaseHas('locations', ['code' => '79', 'name' => 'Hồ Chí Minh']);
    $this->assertDatabaseHas('job_categories', ['slug' => 'livestream']);
});

it('enforces unique location codes', function () {
    Location::create(['code' => '01', 'name' => 'Hà Nội']);
    Location::create(['code' => '01', 'name' => 'Trùng mã']);
})->throws(QueryException::class);

it('enforces unique category slugs', function () {
    JobCategory::create(['slug' => 'marketing', 'name' => 'Marketing']);
    JobCategory::create(['slug' => 'marketing', 'name' => 'Trùng mã']);
})->throws(QueryException::class);

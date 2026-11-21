<?php

use App\Enums\JobStatus;
use App\Models\Job;
use App\Models\JobCategory;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function filterableJob(User $staff, array $attributes = [], string $companyType = 'direct'): Job
{
    $job = managedJob($staff);
    $job->update(array_replace(['salary_min' => 15000000, 'experience_years' => 1], $attributes));
    $job->company->update(['type' => $companyType]);

    return publishManagedJob($job);
}

it('filters public jobs by the advertised starting salary', function () {
    $staff = jobManager();
    $low = managedJob($staff);
    $low->update(['salary_min' => 5000000, 'salary_max' => 20000000]);
    publishManagedJob($low);
    $high = managedJob($staff);
    $high->update(['salary_min' => 15000000]);
    publishManagedJob($high);
    expect($this->get('/viec-lam?luong_tu=10000000')->assertOk()->viewData('jobs')->pluck('id')->all())->toBe([$high->id]);
});

it('filters each supported field and restores its selected value from the URL', function (string $field) {
    $staff = jobManager();
    $match = filterableJob($staff);
    $otherLocation = Location::create(['code' => '56', 'name' => 'Khánh Hòa']);
    $otherCategory = JobCategory::create(['slug' => 'marketing', 'name' => 'Marketing']);
    $differences = ['dia_diem' => ['location_id' => $otherLocation->id], 'vi_tri' => ['category_id' => $otherCategory->id], 'luong_tu' => ['salary_min' => 5000000], 'kinh_nghiem' => ['experience_years' => 3], 'loai_cong_ty' => []];
    filterableJob($staff, $differences[$field], $field === 'loai_cong_ty' ? 'agency' : 'direct');
    $values = ['dia_diem' => $match->location_id, 'vi_tri' => $match->category_id, 'luong_tu' => 10000000, 'kinh_nghiem' => 1, 'loai_cong_ty' => 'direct'];
    $url = '/viec-lam?'.http_build_query([$field => $values[$field]]);
    foreach (range(1, 2) as $attempt) {
        $response = $this->get($url)->assertOk();
        expect($response->viewData('jobs')->pluck('id')->all())->toBe([$match->id]);
        $doc = new DOMDocument;
        $doc->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        expect((new DOMXPath($doc))->query('//select[@name="'.$field.'"]/option[@selected and @value="'.$values[$field].'"]')->length)->toBe(1);
    }
})->with(['dia_diem', 'vi_tri', 'luong_tu', 'kinh_nghiem', 'loai_cong_ty']);

it('combines all filters with AND and keeps excluding nonpublic jobs', function () {
    $staff = jobManager();
    $match = filterableJob($staff);
    $location = Location::create(['code' => '56', 'name' => 'Khánh Hòa']);
    $category = JobCategory::create(['slug' => 'marketing', 'name' => 'Marketing']);
    foreach ([['location_id' => $location->id], ['category_id' => $category->id], ['salary_min' => 5000000], ['experience_years' => 3]] as $change) {
        filterableJob($staff, $change);
    }
    filterableJob($staff, [], 'agency');
    $paused = filterableJob($staff);
    $paused->transitionTo(JobStatus::Paused);
    $filters = ['dia_diem' => $match->location_id, 'vi_tri' => $match->category_id, 'luong_tu' => 10000000, 'kinh_nghiem' => 1, 'loai_cong_ty' => 'direct'];
    expect($this->get('/viec-lam?'.http_build_query($filters))->assertOk()->viewData('jobs')->pluck('id')->all())->toBe([$match->id]);
});

it('supports zero experience and excludes negotiable or unknown salaries only when filtering salary', function () {
    $staff = jobManager();
    $entry = filterableJob($staff, ['experience_years' => 0]);
    $negotiable = filterableJob($staff, ['experience_years' => 0, 'negotiable' => true]);
    $unknown = filterableJob($staff, ['experience_years' => 0, 'salary_min' => null]);
    expect($this->get('/viec-lam?kinh_nghiem=0')->assertOk()->viewData('jobs')->pluck('id')->all())->toBe([$unknown->id, $negotiable->id, $entry->id]);
    expect($this->get('/viec-lam?kinh_nghiem=0&luong_tu=10000000')->assertOk()->viewData('jobs')->pluck('id')->all())->toBe([$entry->id]);
});

it('preserves filters across pagination and resets the page on form submission', function () {
    $staff = jobManager();
    for ($i = 0; $i < 13; $i++) {
        filterableJob($staff, ['experience_years' => 0]);
    }
    filterableJob($staff, [], 'agency');
    $url = '/viec-lam?kinh_nghiem=0&loai_cong_ty=direct&ignored=secret';
    $first = $this->get($url)->assertOk();
    $next = $first->viewData('jobs')->nextPageUrl();
    expect($next)->toContain('kinh_nghiem=0', 'loai_cong_ty=direct', 'page=2')->not->toContain('ignored');
    $second = $this->get($next)->assertOk();
    expect($second->viewData('jobs')->count())->toBe(1);
    expect($this->get($url)->assertOk()->viewData('jobs')->pluck('id')->all())->toBe($first->viewData('jobs')->pluck('id')->all());
    $doc = new DOMDocument;
    $doc->loadHTML($second->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    expect((new DOMXPath($doc))->query('//form[@method="get"]//input[@name="page"]')->length)->toBe(0);
});

it('rejects malformed known filters without redirect loops or reflecting unsafe input', function (string $query) {
    $this->get('/viec-lam?'.$query)->assertStatus(422)->assertSee(__('public.invalid_filters'))->assertDontSee('<script>bad()</script>', false);
})->with(['dia_diem=999999', 'vi_tri[]=1', 'luong_tu=-1', 'kinh_nghiem=999', 'loai_cong_ty=admin', 'loai_cong_ty=%3Cscript%3Ebad()%3C%2Fscript%3E']);

<?php

use App\Enums\JobStatus;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('finds Vietnamese job titles without accents', function () {
    $staff = jobManager();
    $match = managedJob($staff);
    $match->update(['title' => 'Nhân viên kế toán']);
    publishManagedJob($match);
    publishManagedJob(managedJob($staff));
    expect($this->get('/viec-lam?q=ke+toan')->assertOk()->viewData('jobs')->pluck('id')->all())->toBe([$match->id]);
});

it('finds company names such as Nguyen without accents and ignores letter case', function () {
    $staff = jobManager();
    $job = publishManagedJob(managedJob($staff));
    $job->company->update(['name' => 'Công ty Nguyễn Đồng']);
    publishManagedJob(managedJob($staff));
    foreach (['Nguyen', 'NGUYỄN', 'dong', 'ĐỒNG'] as $query) {
        expect($this->get('/viec-lam?'.http_build_query(['q' => $query]))->assertOk()->viewData('jobs')->pluck('id')->all())->toBe([$job->id]);
    }
});

it('normalizes Unicode and whitespace while retaining the keyword in the form', function () {
    $staff = jobManager();
    $job = managedJob($staff);
    $job->update(['title' => 'Nhân viên kế   toán']);
    publishManagedJob($job);
    $term = Normalizer::normalize('KẾ TOÁN', Normalizer::FORM_D);
    $response = $this->get('/viec-lam?'.http_build_query(['q' => '  '.$term.'  ']))->assertOk();
    expect($response->viewData('jobs')->pluck('id')->all())->toBe([$job->id]);
    $response->assertSee('value="KẾ TOÁN"', false);
});

it('treats wildcard characters as literal text and binds SQL special characters safely', function (string $term, bool $matches) {
    $staff = jobManager();
    $job = managedJob($staff);
    $job->update(['title' => 'Nhân viên thử 100%_!']);
    publishManagedJob($job);
    publishManagedJob(managedJob($staff));
    expect($this->get('/viec-lam?'.http_build_query(['q' => $term]))->assertOk()->viewData('jobs')->pluck('id')->all())->toBe($matches ? [$job->id] : []);
})->with([['%', true], ['_', true], ['!', true], ["' OR 1=1 --", false], ['\\', false], ['％', true]]);

it('treats empty and whitespace keywords as no search condition', function (string $term) {
    $job = publishManagedJob(managedJob(jobManager()));
    expect($this->get('/viec-lam?'.http_build_query(['q' => $term]))->assertOk()->viewData('jobs')->pluck('id')->all())->toBe([$job->id]);
})->with(['', '   ', "\t\n", "\u{00a0}"]);

it('rejects too long malformed and NUL keywords without a server error', function (string $query) {
    $this->get('/viec-lam?'.$query)->assertStatus(422)->assertSee(__('public.invalid_filters'));
})->with(['q='.str_repeat('x', 201), 'q[]=ke+toan', 'q=abc%00def', 'q='.str_repeat('a', 10000)]);

it('escapes the search input without generating executable attributes', function () {
    $term = '"><script>searchXss()</script>';
    $this->get('/viec-lam?'.http_build_query(['q' => $term]))->assertOk()->assertSee($term)->assertDontSee('<script>searchXss()</script>', false);
});

it('combines search and filters without allowing an OR match to expose a paused job', function () {
    $staff = jobManager();
    $visible = managedJob($staff);
    $visible->update(['title' => 'Kế toán', 'salary_min' => 15000000]);
    publishManagedJob($visible);
    $lowSalary = managedJob($staff);
    $lowSalary->update(['title' => 'Kế toán', 'salary_min' => 5000000]);
    publishManagedJob($lowSalary);
    $paused = publishManagedJob(managedJob($staff));
    $paused->company->update(['name' => 'Công ty kế toán']);
    $paused->transitionTo(JobStatus::Paused);
    expect($this->get('/viec-lam?q=ke+toan&luong_tu=10000000')->assertOk()->viewData('jobs')->pluck('id')->all())->toBe([$visible->id]);
});

it('keeps the keyword in pagination links and stable result pages', function () {
    $staff = jobManager();
    for ($index = 0; $index < 13; $index++) {
        $job = managedJob($staff);
        $job->update(['title' => 'Kế toán '.$index]);
        publishManagedJob($job);
    }
    $first = $this->get('/viec-lam?q=ke+toan&loai_cong_ty=direct')->assertOk()->viewData('jobs');
    $next = $first->nextPageUrl();
    expect($next)->toContain('q=ke%20toan', 'loai_cong_ty=direct');
    $second = $this->get($next)->assertOk()->viewData('jobs');
    expect($second->count())->toBe(1)->and($second->pluck('id')->intersect($first->pluck('id'))->all())->toBe([]);
});

it('updates searchable text for model and direct SQL changes without exposing the stored normalization', function () {
    $staff = jobManager();
    $job = managedJob($staff);
    $job->update(['title' => 'Kế toán']);
    publishManagedJob($job);
    DB::table('jobs')->where('id', $job->id)->update(['title' => 'Điều phối', 'search_text' => 'tampered']);
    $job->company->update(['name' => 'Nguyễn']);
    expect($this->get('/viec-lam?q=dieu+phoi')->assertOk()->viewData('jobs')->pluck('id')->all())->toBe([$job->id]);
    expect($this->get('/viec-lam?q=ke+toan')->assertOk()->viewData('jobs')->total())->toBe(0);
    expect($job->fresh()->toJson())->not->toContain('search_text')->and($job->company->fresh()->toJson())->not->toContain('search_text');
});

it('backfills existing rows and keeps data intact through a search migration rollback and reapply', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $migration = require database_path('migrations/2026_11_21_000012_add_unaccent_job_search.php');
    $migration->down();
    expect(Schema::hasColumn('jobs', 'search_text'))->toBeFalse();
    DB::table('jobs')->where('id', $job->id)->update(['title' => 'Kế toán cũ']);
    DB::table('companies')->where('id', $job->company_id)->update(['name' => 'Nguyễn cũ']);
    $migration->up();
    expect(DB::table('jobs')->where('id', $job->id)->value('search_text'))->toBe('ke toan cu')
        ->and(DB::table('companies')->where('id', $job->company_id)->value('search_text'))->toBe('nguyen cu')
        ->and(Job::findOrFail($job->id)->status)->toBe(JobStatus::Published);
});

it('installs the required extensions and makes normalized LIKE patterns eligible for trigram indexes', function () {
    expect(DB::table('pg_extension')->whereIn('extname', ['unaccent', 'pg_trgm'])->count())->toBe(2);
    // Small test tables normally prefer a sequential scan; prove index eligibility, not a production benchmark.
    DB::statement('SET LOCAL enable_seqscan = off');
    foreach (['jobs', 'companies'] as $table) {
        $plan = DB::select("EXPLAIN (FORMAT JSON) SELECT id FROM {$table} WHERE search_text LIKE public.viz_search_normalize(?) ESCAPE '!'", ['%ke toan%']);
        expect(json_encode($plan))->toContain($table.'_search_text_trgm');
    }
});

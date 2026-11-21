<?php

use App\Enums\JobStatus;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('does not expose missing unverified partially verified or deleted companies', function () {
    $company = Company::create(['name' => 'PRIVATE_COMPANY']);
    $this->get('/cong-ty/'.$company->id)->assertNotFound()->assertDontSee('PRIVATE_COMPANY');
    $company->forceFill(['verified_at' => now()])->save();
    $this->get('/cong-ty/'.$company->id)->assertNotFound();
    $company->forceFill(['verified_by' => jobManager()->id, 'verified_at' => null])->save();
    $this->get('/cong-ty/'.$company->id)->assertNotFound();
    $company->forceFill(['verified_at' => now()])->save();
    $company->delete();
    foreach ([$company->id, '9999999', '9223372036854775808', '0', 'abc'] as $id) {
        $this->get('/cong-ty/'.$id)->assertNotFound();
    }
});

it('shows only the public company projection with escaped profile text and safe external channels', function () {
    $job = managedJob(jobManager());
    $company = $job->company;
    $company->update(['address' => 'Số 12, Phường mới, Hà Nội', 'introduction' => '<script>companyXss()</script>', 'industry' => 'Thương mại điện tử', 'products' => 'Sản phẩm thử', 'tax_code' => 'INTERNAL_TAX', 'logo_path' => 'PRIVATE_PATH']);
    $company->channels()->create(['type' => 'website', 'display_name' => 'Website riêng', 'url' => 'https://example.org/shop']);
    $company->channels()->create(['type' => 'tiktok', 'display_name' => 'Kênh video', 'url' => 'https://www.tiktok.com/@demo']);
    $response = $this->get('/cong-ty/'.$company->id)->assertOk()->assertSee($company->address)->assertSee('Thương mại điện tử')->assertSee('Sản phẩm thử')
        ->assertSee('&lt;script&gt;companyXss()', false)->assertDontSee('<script>companyXss()', false)
        ->assertSee('target="_blank" rel="noopener noreferrer"', false)->assertSee('https://example.org/shop')
        ->assertDontSee('INTERNAL_TAX')->assertDontSee('PRIVATE_PATH')->assertHeader('Cache-Control', 'no-store, private');
    expect(array_keys($response->viewData('company')->getAttributes()))->toBe(['id', 'name', 'type', 'address', 'introduction', 'industry', 'products']);
});

it('omits unsafe legacy channel URLs without fetching or embedding external content', function () {
    $company = managedJob(jobManager())->company;
    foreach (['javascript:alert(1)', 'https://tiktok.com.attacker.test/', 'https://user:pass@tiktok.com/'] as $url) {
        DB::table('company_channels')->insert(['company_id' => $company->id, 'type' => 'tiktok', 'display_name' => 'UNSAFE_CHANNEL', 'url' => $url, 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->get('/cong-ty/'.$company->id)->assertOk()->assertDontSee('UNSAFE_CHANNEL')->assertDontSee('<iframe', false);
});

it('lists only the selected company open jobs with stable pagination', function () {
    $staff = jobManager();
    $first = publishManagedJob(managedJob($staff));
    $ids = [$first->id];
    for ($i = 0; $i < 12; $i++) {
        $copy = $first->replicate(['slug', 'published_at', 'expires_at']);
        $copy->forceFill(['status' => JobStatus::Draft]);
        $copy->save();
        publishManagedJob($copy);
        $ids[] = $copy->id;
    }
    publishManagedJob(managedJob($staff));
    DB::table('jobs')->whereIn('id', $ids)->update(['published_at' => now()->subDay()]);
    $response = $this->get('/cong-ty/'.$first->company_id)->assertOk();
    expect($response->viewData('jobs')->total())->toBe(13);
    expect($response->viewData('jobs')->pluck('id')->all())->toBe(array_slice(array_reverse($ids), 0, 12));
    $this->get('/cong-ty/'.$first->company_id.'?page=2')->assertOk();
    $first->transitionTo(JobStatus::Paused);
    expect($this->get('/cong-ty/'.$first->company_id)->viewData('jobs')->total())->toBe(12);
    $first->company->forceFill(['verified_at' => null])->save();
    $this->get('/cong-ty/'.$first->company_id)->assertNotFound();
});

it('links every publicly viewable job state to its real company page', function (JobStatus $status) {
    $job = publishManagedJob(managedJob(jobManager()));
    if ($status !== JobStatus::Published) {
        $job->transitionTo($status);
    }
    $this->get(route('jobs.show', ['slug' => $job->slug, 'id' => $job->id]))->assertOk()->assertSee(route('companies.show', ['id' => $job->company_id]));
})->with([JobStatus::Published, JobStatus::Paused, JobStatus::Expired, JobStatus::Closed]);

it('keeps verified companies accessible when they have no open jobs', function () {
    $job = managedJob(jobManager());
    $this->get('/cong-ty/'.$job->company_id)->assertOk()->assertSee(__('public.no_jobs_title'));
});

<?php

use App\Enums\JobStatus;
use App\Support\ApplicationSource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('ignores unsafe malformed or oversized source values without changing page content', function (string $query) {
    $this->withSession([ApplicationSource::SESSION_KEY => 'zalo-old'])->get('/?'.$query)->assertOk()
        ->assertSessionHas(ApplicationSource::SESSION_KEY, 'zalo-old')->assertSee(__('public.hero_first'));
})->with(['nguon[]=zalo', 'nguon=%3Cscript%3Ebad%3C%2Fscript%3E', 'nguon=https%3A%2F%2Fexample.org', 'nguon=abc%00def', 'nguon='.str_repeat('a', 65), 'nguon=', 'nguon=nh%C3%B3m', 'nguon=abc%0A', 'nguon=foo.bar', 'nguon=%20zalo%20']);

it('remembers the last valid public source and retains it across visits without a new source', function () {
    $this->get('/?nguon=Zalo-Nhom_1')->assertOk()->assertSessionHas(ApplicationSource::SESSION_KEY, 'zalo-nhom_1');
    $this->get('/viec-lam')->assertOk()->assertSessionHas(ApplicationSource::SESSION_KEY, 'zalo-nhom_1');
    $this->get('/viec-lam?nguon=facebook')->assertOk()->assertSessionHas(ApplicationSource::SESSION_KEY, 'facebook');
    $this->get('/?nguon='.str_repeat('a', 64))->assertOk()->assertSessionHas(ApplicationSource::SESSION_KEY, str_repeat('a', 64));
});

it('does not record sources from private authentication sitemap or hidden job responses', function () {
    $job = managedJob(jobManager());
    foreach (['/login', '/sitemap.xml', '/robots.txt', '/app', '/viec-lam/'.$job->slug.'-'.$job->id, '/cong-ty/999999', '/viec-lam?dia_diem=invalid'] as $path) {
        $this->withSession([ApplicationSource::SESSION_KEY => 'existing'])->get($path.(str_contains($path, '?') ? '&' : '?').'nguon=bad-origin')->assertSessionHas(ApplicationSource::SESSION_KEY, 'existing');
    }
});

it('retains the source through a canonical slug redirect without putting it into canonical metadata', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $url = route('jobs.show', ['slug' => $job->slug, 'id' => $job->id]);
    $this->get('/viec-lam/old-'.$job->id.'?nguon=zalo-nhom2')->assertRedirect($url)->assertSessionHas(ApplicationSource::SESSION_KEY, 'zalo-nhom2');
    $response = $this->get($url)->assertOk()->assertSessionHas(ApplicationSource::SESSION_KEY, 'zalo-nhom2');
    $response->assertDontSee('zalo-nhom2')->assertSee('nguon=chia-se');
});

it('keeps public metadata and content identical for different valid sources', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $url = route('jobs.show', ['slug' => $job->slug, 'id' => $job->id]);
    $normalize = fn (string $html): string => preg_replace('/nonce="[^"]+"|name="(?:submission_token|_token)" value="[^"]+"/', 'request-token', $html);
    expect($normalize($this->get($url.'?nguon=zalo-a')->getContent()))->toBe($normalize($this->get($url.'?nguon=zalo-b')->getContent()));
});

it('offers a fixed share URL on receiving jobs with a no-script fallback and unchanged strict CSP', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $response = $this->get(route('jobs.show', ['slug' => $job->slug, 'id' => $job->id]))->assertOk()
        ->assertSee('id="share-url" type="url" readonly', false)->assertSee('nguon=chia-se')->assertSee(__('public.copy_link'))->assertSee('name="submission_token"', false);
    $csp = $response->headers->get('Content-Security-Policy');
    preg_match('/nonce="([^"]+)"/', $response->getContent(), $match);
    expect($csp)->toContain("'nonce-{$match[1]}'")->not->toContain("'unsafe-eval'");
    expect(explode(';', explode('script-src ', $csp)[1])[0])->not->toContain("'unsafe-inline'");
    expect(ApplicationSource::current(request()->session()))->toBeNull();
});

it('does not offer sharing on nonreceiving jobs', function (JobStatus $status) {
    $job = publishManagedJob(managedJob(jobManager()));
    $job->transitionTo($status);
    $this->get(route('jobs.show', ['slug' => $job->slug, 'id' => $job->id]))->assertOk()->assertDontSee('data-job-share', false)->assertDontSee('nguon=chia-se');
})->with([JobStatus::Paused, JobStatus::Expired, JobStatus::Closed]);

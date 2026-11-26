<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('redirects successful submissions to a private noindex thank you page without candidate data', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);
    $job = publishManagedJob(managedJob(jobManager()));
    $form = $this->get(route('jobs.show', ['slug' => $job->slug, 'id' => $job->id]));
    preg_match('/name="submission_token" value="([a-f0-9]+)"/', $form->getContent(), $match);
    $this->withCookie(config('session.cookie'), session()->getId());
    $this->post(route('applications.store', ['id' => $job->id]), [
        'full_name' => 'PRIVATE_RECEIPT_NAME', 'phone' => '0912345678', 'email' => 'private-receipt@example.test',
        'consent' => '1', 'submission_token' => $match[1], 'cf-turnstile-response' => 'receipt-test-token',
    ])->assertSessionHasNoErrors()->assertRedirect(route('applications.thanks'));
    $response = $this->get(route('applications.thanks'))->assertOk()->assertSee(__('applications.received'))
        ->assertSee('noindex, follow', false)->assertDontSee('PRIVATE_RECEIPT_NAME')->assertDontSee('0912345678')
        ->assertDontSee('private-receipt@example.test')->assertDontSee('JobPosting');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->get('Content-Security-Policy'))->not->toContain('unsafe-eval', 'challenges.cloudflare.com');
    $this->get(route('applications.thanks'))->assertOk();
});

it('keeps the thank you page generic and does not add it to the sitemap', function () {
    $this->get(route('applications.thanks', ['name' => 'FORGED_CANDIDATE']))->assertOk()->assertDontSee('FORGED_CANDIDATE');
    $this->get(route('sitemap.pages'))->assertDontSee(route('applications.thanks'));
});

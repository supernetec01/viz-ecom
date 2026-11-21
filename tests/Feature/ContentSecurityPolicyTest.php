<?php

use App\Enums\JobStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

uses(RefreshDatabase::class);

function scriptPolicy(string $header): string
{
    preg_match('/(?:^|;)\s*script-src\s+([^;]+)/', $header, $match);

    return $match[1] ?? '';
}

it('uses an independent request nonce for panel scripts without unsafe inline', function () {
    $this->actingAs(jobManager());
    $first = $this->get('/app/tin-tuyen-dung')->assertOk();
    $policy = scriptPolicy($first->headers->get('Content-Security-Policy'));
    expect($policy)->toContain("'unsafe-eval'", "'nonce-")->not->toContain("'unsafe-inline'");
    preg_match("/'nonce-([^']+)'/", $policy, $match);
    $nonce = $match[1] ?? '';
    expect(strlen($nonce))->toBeGreaterThanOrEqual(32);
    $document = new DOMDocument;
    $document->loadHTML($first->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    expect($document->getElementsByTagName('script')->length)->toBeGreaterThan(0);
    foreach ($document->getElementsByTagName('script') as $script) {
        expect($script->getAttribute('nonce'))->toBe($nonce);
    }
    $runtimeScripts = (new DOMXPath($document))->query('//script[@data-update-uri]');
    expect($runtimeScripts->length)->toBe(1);
    expect($runtimeScripts->item(0)->getAttribute('src'))->not->toContain('-csp');
    $second = $this->get('/app/tin-tuyen-dung')->assertOk();
    expect(scriptPolicy($second->headers->get('Content-Security-Policy')))->not->toContain("'nonce-{$nonce}'");
    $first->assertHeader('Cache-Control', 'no-store, private');
});

it('never allows eval or inline scripts on public and login responses', function (string $path) {
    $response = $this->get($path);
    $policy = scriptPolicy($response->headers->get('Content-Security-Policy'));
    expect($policy)->not->toContain("'unsafe-eval'")->not->toContain("'unsafe-inline'");
})->with(['/', '/login', '/forgot-password', '/app/login', '/application', '/login?panel=app&eval=1']);

it('does not leak the panel runtime or CSP to a subsequent public request', function () {
    $this->actingAs(jobManager())->get('/app')->assertOk();
    $response = $this->get('/');
    expect(scriptPolicy($response->headers->get('Content-Security-Policy')))->not->toContain("'unsafe-eval'")
        ->and(config('livewire.csp_safe'))->toBeTrue();
});

it('serves the matching Livewire build without granting eval to its asset response', function () {
    $path = EndpointResolver::scriptPath(minified: false);
    $standard = $this->get($path.'?id=test-standard')->assertOk();
    expect(hash_file('sha256', $standard->baseResponse->getFile()->getPathname()))->toBe(hash_file('sha256', base_path('vendor/livewire/livewire/dist/livewire.js')));
    expect(scriptPolicy($standard->headers->get('Content-Security-Policy')))->not->toContain("'unsafe-eval'");
    $safe = $this->get($path.'?id=test-csp')->assertOk();
    expect(hash_file('sha256', $safe->baseResponse->getFile()->getPathname()))->toBe(hash_file('sha256', base_path('vendor/livewire/livewire/dist/livewire.csp.js')));
});

it('overrides even the panel exception with a sandboxed private file policy', function (string $path) {
    Route::get($path, fn () => response('Synthetic file'))->middleware('csp.private-file');
    $response = $this->get($path)->assertOk();
    $header = $response->headers->get('Content-Security-Policy');
    expect($header)->toContain("default-src 'none'", "script-src 'none'", 'sandbox', "form-action 'none'");
    foreach (['unsafe-eval', 'unsafe-inline', 'allow-scripts', 'allow-same-origin', 'nonce-'] as $forbidden) {
        expect($header)->not->toContain($forbidden);
    }
    $response->assertHeader('Cache-Control', 'no-store, private');
})->with(['/app/_test-private-file', '/_test-private-file']);

it('keeps a real encrypted session across mounting and confirming a panel action', function () {
    config(['session.driver' => 'database']);
    $actor = jobManager();
    $job = publishManagedJob(managedJob($actor));
    $this->actingAs($actor);
    $initial = $this->get('/app/tin-tuyen-dung')->assertOk();
    // actingAs sets a guard only. Persist its login before testing subsequent cookie-only requests.
    session()->put(Auth::guard()->getName(), $actor->id);
    session()->save();
    foreach ($initial->headers->getCookies() as $cookie) {
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
    $document = new DOMDocument;
    $document->loadHTML($initial->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    $snapshot = null;
    foreach ((new DOMXPath($document))->query('//*[@*[name()="wire:snapshot"]]') as $element) {
        $candidate = $element->getAttribute('wire:snapshot');
        if (str_contains(strtolower(str_replace(['-', '\\'], '', json_decode($candidate, true)['memo']['name'])), 'listjobs')) {
            $snapshot = $candidate;
            break;
        }
    }
    expect($snapshot)->not->toBeNull();
    foreach ([['mountAction', ['pause', [], ['recordKey' => (string) $job->id, 'table' => true]]], ['callMountedAction', []]] as [$method, $params]) {
        Auth::forgetGuards();
        $response = $this->postJson(app('livewire')->getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot, 'updates' => [], 'calls' => [['method' => $method, 'params' => $params]],
        ]]], ['X-Livewire' => 'true'])->assertOk();
        $snapshot = $response->json('components.0.snapshot');
        expect($snapshot)->toBeString();
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
    }
    expect($job->fresh()->status)->toBe(JobStatus::Paused);
    Auth::forgetGuards();
    $this->get('/app/tin-tuyen-dung')->assertOk();
});

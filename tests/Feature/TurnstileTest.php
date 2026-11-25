<?php

use App\Services\VerifyTurnstile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
});

it('verifies every token server side and accepts a valid verification', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);
    app(VerifyTurnstile::class)->verify('synthetic-valid-token');
    Http::assertSent(fn ($request) => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
        && $request['response'] === 'synthetic-valid-token');
});

it('rejects an invalid captcha token', function () {
    Http::fake(['*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);
    app(VerifyTurnstile::class)->verify('synthetic-invalid-token');
})->throws(ValidationException::class);

it('rejects reuse even when the remote verifier would accept the token again', function () {
    Http::fake(['*' => Http::response(['success' => true])]);
    app(VerifyTurnstile::class)->verify('synthetic-repeated-token');
    try {
        app(VerifyTurnstile::class)->verify('synthetic-repeated-token');
        test()->fail('A spent token must be rejected.');
    } catch (ValidationException) {
        Http::assertSentCount(1);
    }
});

it('rejects remote spent tokens', function () {
    Http::fake(['*' => Http::response(['success' => false, 'error-codes' => ['timeout-or-duplicate']])]);
    app(VerifyTurnstile::class)->verify('synthetic-spent-token');
})->throws(ValidationException::class);

it('fails closed on verification timeout without exposing exception details', function () {
    Http::fake(fn () => throw new ConnectionException('Synthetic private HTTP payload'));
    try {
        app(VerifyTurnstile::class)->verify('synthetic-timeout');
        test()->fail('Timeout must reject submission.');
    } catch (ValidationException $exception) {
        expect($exception->getPrevious())->toBeNull();
        expect($exception->errors())->toBe(['cf-turnstile-response' => [__('applications.captcha_failed')]]);
    }
});

it('fails closed on a remote service error or malformed response', function (array|string $body, int $status) {
    Http::fake(['*' => Http::response($body, $status)]);
    app(VerifyTurnstile::class)->verify('synthetic-service-error');
})->with([[['success' => true], 503], ['not json', 200], [['success' => 'true'], 200]])->throws(ValidationException::class);

it('rejects missing or demo configuration in production before sending any HTTP request', function (array $keys) {
    app()->detectEnvironment(fn () => 'production');
    config($keys);
    try {
        app(VerifyTurnstile::class)->verify('synthetic-production');
        test()->fail('Production requires real keys.');
    } catch (ValidationException) {
        Http::assertNothingSent();
    }
})->with([
    [['turnstile.site_key' => null, 'turnstile.secret_key' => null]],
    [['turnstile.site_key' => '1x00000000000000000000AA', 'turnstile.secret_key' => '1x0000000000000000000000000000000AA']],
]);

it('checks production hostname and action', function (string $hostname, string $action, bool $allowed) {
    app()->detectEnvironment(fn () => 'production');
    config(['turnstile.site_key' => 'real-site-configured', 'turnstile.secret_key' => 'real-secret-configured', 'app.url' => 'https://jobs.example.test']);
    Http::fake(['*' => Http::response(['success' => true, 'hostname' => $hostname, 'action' => $action])]);
    if (! $allowed) {
        expect(fn () => app(VerifyTurnstile::class)->verify('synthetic-host'))->toThrow(ValidationException::class);
    } else {
        app(VerifyTurnstile::class)->verify('synthetic-host');
        Http::assertSentCount(1);
    }
})->with([['jobs.example.test', 'apply_job', true], ['evil.example.test', 'apply_job', false], ['jobs.example.test', 'login', false]]);

<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class VerifyTurnstile
{
    public function siteKey(): string
    {
        return (string) (config('turnstile.site_key') ?: (app()->environment('local', 'testing') ? '1x00000000000000000000AA' : ''));
    }

    public function verify(string $token): void
    {
        $secret = (string) (config('turnstile.secret_key') ?: (app()->environment('local', 'testing') ? '1x0000000000000000000000000000000AA' : ''));
        $site = $this->siteKey();
        $demo = app()->environment('local', 'testing');
        if ($secret === '' || $site === '' || (! $demo && (preg_match('/^[123]x0{10,}/', $secret) || preg_match('/^[123]x0{10,}/', $site)))) {
            $this->reject();
        }
        try {
            // Store only a digest. Atomic add also closes Siteverify's replay convergence window.
            if (! Cache::add('turnstile-used:'.hash('sha256', $token), true, now()->addMinutes(10))) {
                $this->reject();
            }
            $response = Http::asForm()->withoutRedirecting()->connectTimeout(2)->timeout(5)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['secret' => $secret, 'response' => $token]);
            $result = $response->json();
            $valid = $response->successful() && is_array($result) && ($result['success'] ?? false) === true;
            if (! $demo) {
                $valid = $valid && ($result['action'] ?? '') === 'apply_job'
                    && strtolower((string) ($result['hostname'] ?? '')) === strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
            }
            if (! $valid) {
                $this->reject();
            }
        } catch (Throwable) {
            // Never propagate the HTTP exception: its body can contain the token/secret.
            $this->reject();
        }
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['cf-turnstile-response' => __('applications.captcha_failed')]);
    }
}

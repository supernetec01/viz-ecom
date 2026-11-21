<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $panel = $request->is('app', 'app/*') && ! $request->is('app/login', 'app/login/*');
        $previousRuntime = config('livewire.csp_safe');
        // Livewire serves both builds at one endpoint, with distinct version IDs.
        $script = in_array($request->path(), [ltrim(EndpointResolver::scriptPath(), '/'), ltrim(EndpointResolver::scriptPath(minified: true), '/')], true);
        $version = $request->query('id');
        $standardAsset = $script && is_string($version) && $version !== '' && ! str_ends_with($version, '-csp');
        config(['livewire.csp_safe' => ! ($panel || $standardAsset)]);

        try {
            $response = $next($request);
            $privateFile = $request->attributes->get(PrivateFileCsp::ATTRIBUTE) === true;
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('X-Frame-Options', 'DENY');
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
            if ($panel || $privateFile || $request->routeIs('login', 'security', 'password.reset', 'two-factor.login', 'password.confirm', 'jobs.show')) {
                $response->headers->set('Cache-Control', 'no-store, private');
            }
            $scripts = "'self' 'nonce-{$nonce}'".($panel ? " 'unsafe-eval'" : '');
            $policy = $privateFile ? PrivateFileCsp::POLICY : "default-src 'self'; script-src {$scripts}; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";
            $response->headers->set('Content-Security-Policy', $policy);

            return $response;
        } finally {
            // Prevent request-specific runtime selection leaking into another request/worker job.
            config(['livewire.csp_safe' => $previousRuntime]);
        }
    }
}

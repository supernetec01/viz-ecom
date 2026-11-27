<?php

use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureEmployerSession;
use App\Http\Middleware\EnsurePrivilegedMfa;
use App\Http\Middleware\PrivateFileCsp;
use App\Http\Middleware\RequireHttps;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: ['nguon']);
        $middleware->append(SecurityHeaders::class);
        $middleware->append(RequireHttps::class);
        $middleware->web(append: ['throttle:web', EnsureActiveAccount::class, EnsureEmployerSession::class, EnsurePrivilegedMfa::class]);
        $middleware->redirectGuestsTo('/app/login');
        $middleware->alias(['role' => RequireRole::class, 'csp.private-file' => PrivateFileCsp::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (PostTooLargeException $exception) => response()->view('public.upload-too-large', [], 413, [
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; style-src 'self'; font-src 'self'; script-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        ]));
        $exceptions->dontFlash(['code', 'recovery_code', 'token', 'cf-turnstile-response', 'submission_token']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

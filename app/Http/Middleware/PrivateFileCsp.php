<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrivateFileCsp
{
    public const ATTRIBUTE = 'csp.private_file';

    public const POLICY = "default-src 'none'; script-src 'none'; style-src 'none'; img-src 'none'; font-src 'none'; connect-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'; sandbox";

    public function handle(Request $request, Closure $next): Response
    {
        // Server-controlled marker; SecurityHeaders applies this profile even below /app.
        $request->attributes->set(self::ATTRIBUTE, true);

        return $next($request);
    }
}

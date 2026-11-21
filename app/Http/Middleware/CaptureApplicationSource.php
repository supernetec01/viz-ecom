<?php

namespace App\Http\Middleware;

use App\Support\ApplicationSource;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CaptureApplicationSource
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->isMethod('GET') && in_array($response->getStatusCode(), [200, 301], true)) {
            ApplicationSource::remember($request->session(), $request->query('nguon'));
        }

        return $response;
    }
}

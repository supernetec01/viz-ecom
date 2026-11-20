<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user()?->fresh();
        abort_unless($user instanceof User && in_array($user->role->value, $roles, true), 403);

        return $next($request);
    }
}

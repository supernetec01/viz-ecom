<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;

class EnsurePrivilegedMfa
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if (! $user || $user->role === Role::Employer) {
            return $next($request);
        }
        abort_if($request->routeIs('two-factor.disable'), 403);
        if (! $user->hasEnabledTwoFactorAuthentication() && ! $request->routeIs('security', 'two-factor.*', 'password.confirm*', 'logout', 'filament.app.auth.logout', 'login*')) {
            return redirect()->route('security');
        }

        return $next($request);
    }
}

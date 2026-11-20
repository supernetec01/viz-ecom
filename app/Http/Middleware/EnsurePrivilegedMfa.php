<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Illuminate\Http\Request;

class EnsurePrivilegedMfa extends EnsureMultiFactorAuthenticationIsEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->user()?->role === Role::Employer) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}

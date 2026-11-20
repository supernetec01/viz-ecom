<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('two-factor.login*') && ($id = $request->session()->get('login.id'))) {
            $pending = User::find($id);
            $stamp = $request->session()->get('login.password_hash');
            if (! $pending || $pending->status !== UserStatus::Active || ! is_string($stamp) || ! hash_equals(hash('sha256', $pending->password), $stamp)) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login');
            }
        }
        if ($user = $request->user()) {
            if ($user->fresh()?->status !== UserStatus::Active) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect('/app/login');
            }
        }

        return $next($request);
    }
}

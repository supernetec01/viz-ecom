<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use App\Services\EmployerSessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureEmployerSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User) {
            try {
                $current = $user->fresh();
                $valid = $current && ($current->role !== Role::Employer || app(EmployerSessions::class)->valid($current, $request));
            } catch (Throwable) {
                abort(503, __('security.session_unavailable'));
            }
            if (! $valid) {
                Auth::guard('web')->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                if ($request->expectsJson()) {
                    return response()->json(['message' => __('security.session_expired')], 401)->header('Cache-Control', 'no-store');
                }

                return redirect('/app/login')->with('status', __('security.session_expired'));
            }
        }

        return $next($request);
    }
}

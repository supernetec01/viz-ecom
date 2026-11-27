<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecordSuccessfulLogin
{
    public function record(Login $event, Request $request): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User) {
            return;
        }
        try {
            DB::transaction(function () use ($event, $request) {
                app(RecordAudit::class)->login($event->user, $request);
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            });
        } catch (Throwable) {
            $guard = Auth::guard($event->guard);
            // The web guard is session-based; Login is dispatched before setUser.
            $guard->setUser($event->user);
            $guard->logoutCurrentDevice();
            abort(503, __('security.audit_unavailable'));
        }
    }
}

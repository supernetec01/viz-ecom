<?php

namespace App\Providers;

use App\Actions\ResetUserPassword;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Timebox;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::loginView(fn () => view('auth.form', ['mode' => 'login', 'action' => route('login.store')]));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.form', ['mode' => 'forgot', 'action' => route('password.email')]));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.form', [
            'mode' => 'reset', 'action' => route('password.update'), 'token' => $request->route('token'), 'email' => $request->query('email'),
        ]));
        Fortify::confirmPasswordView(fn () => view('auth.form', ['mode' => 'confirm', 'action' => route('password.confirm.store')]));
        Fortify::twoFactorChallengeView(fn () => view('auth.form', ['mode' => 'two_factor', 'action' => route('two-factor.login.store')]));
        Fortify::authenticateUsing(fn (Request $request) => app(Timebox::class)->call(function (Timebox $timebox) use ($request): ?User {
            $user = User::where('email', $request->string('email')->lower()->toString())->first();
            if (! $user || $user->status !== UserStatus::Active || ! Hash::check($request->string('password')->toString(), $user->password)) {
                return null;
            }
            $request->session()->put('login.password_hash', hash('sha256', $user->password));
            $timebox->returnEarly();

            return $user;
        }, 200000));
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip().'|'.$request->string('email')->lower()),
            Limit::perMinute(20)->by('ip:'.$request->ip()),
        ]);
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->ip().'|'.$request->session()->get('login.id')));
    }
}

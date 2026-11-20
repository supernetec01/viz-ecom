<?php

namespace App\Providers\Filament;

use App\Enums\Role;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsurePrivilegedMfa;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('app')->path('app')->login()->passwordReset()->profile()
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()->codeWindow(1)],
                // Guest evaluation also registers the MFA setup route during application boot.
                isRequired: fn (): bool => auth()->user()?->role !== Role::Employer)
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsurePrivilegedMfa::class)
            ->pages([Dashboard::class])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->middleware([
                EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class,
                EnsureActiveAccount::class, AuthenticateSession::class, ShareErrorsFromSession::class,
                PreventRequestForgery::class, SubstituteBindings::class,
                DisableBladeIconComponents::class, DispatchServingFilamentEvent::class, 'throttle:web',
            ], isPersistent: true)
            ->authMiddleware([Authenticate::class], isPersistent: true);
    }
}

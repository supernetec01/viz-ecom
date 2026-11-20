<?php

namespace App\Providers\Filament;

use App\Http\Controllers\AuthController;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsurePrivilegedMfa;
use Filament\Actions\Action;
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
        return $panel->default()->id('app')->path('app')->login([AuthController::class, 'login'])->profile()
            ->userMenuItems(['security' => Action::make('security')->label(__('ui.security'))->url(fn () => route('security'))])
            ->pages([Dashboard::class])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->middleware([
                EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class,
                EnsureActiveAccount::class, AuthenticateSession::class, ShareErrorsFromSession::class,
                PreventRequestForgery::class, SubstituteBindings::class,
                DisableBladeIconComponents::class, DispatchServingFilamentEvent::class, 'throttle:web',
            ], isPersistent: true)
            ->authMiddleware([Authenticate::class, EnsurePrivilegedMfa::class], isPersistent: true);
    }
}

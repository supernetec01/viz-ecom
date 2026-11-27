<?php

namespace App\Providers\Filament;

use App\Filament\LocalAvatarProvider;
use App\Filament\Widgets\FileReviewStats;
use App\Http\Controllers\AuthController;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureEmployerSession;
use App\Http\Middleware\EnsurePrivilegedMfa;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
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
        return $panel->default()->id('app')->path('app')->login([AuthController::class, 'login'])->profile()->databaseTransactions()
            ->databaseNotifications()->databaseNotificationsPollingInterval('30s')
            ->userMenuItems(['security' => Action::make('security')->label(__('ui.security'))->url(fn () => route('security'))])
            ->defaultAvatarProvider(LocalAvatarProvider::class)
            ->renderHook(PanelsRenderHook::SCRIPTS_AFTER, fn () => view('filament.livewire-scripts'))
            ->pages([Dashboard::class])
            ->widgets([FileReviewStats::class])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->middleware([
                EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class,
                EnsureActiveAccount::class, EnsureEmployerSession::class, AuthenticateSession::class, ShareErrorsFromSession::class,
                PreventRequestForgery::class, SubstituteBindings::class,
                DisableBladeIconComponents::class, DispatchServingFilamentEvent::class, 'throttle:web',
            ])
            ->authMiddleware([Authenticate::class, EnsureEmployerSession::class, EnsurePrivilegedMfa::class], isPersistent: true);
    }
}

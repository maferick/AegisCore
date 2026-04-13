<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * AegisCore admin panel (Filament 5) mounted at `/admin`.
 *
 * Phase 1: empty shell. Resources / Pages / Widgets are auto-discovered from
 * `app/Filament/{Resources,Pages,Widgets}` — the directories are empty for
 * now, so `/admin` is the stock Filament dashboard behind a login screen.
 * We'll land the first Resource (user management) in a follow-up once
 * UsersCharacters picks a role model; dropping scaffolds now would just be
 * preemptive churn.
 *
 * Access model is defined on `App\Models\User::canAccessPanel()` — see there
 * for the phase-1 gate (any authenticated user → admin; tightens to a role
 * check when spatie/laravel-permission gets wired into the users table).
 *
 * The primary colour is the same `#ff6b35` accent the landing page uses, so
 * the admin and the marketing page feel like the same product.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                // EVE HUD palette — cyan is the "go / selected / friendly"
                // colour in-game, and the landing page uses it as the primary
                // accent, so the admin shares the same language.
                'primary' => Color::Cyan,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}

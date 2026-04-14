<?php

namespace App\Providers\Filament;

use App\Services\Eve\Sso\EveSsoClient;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
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
 * Access model is defined on `App\Models\User::canAccessPanel()` — the
 * phase-1 gate checks `EVE_SSO_ADMIN_CHARACTER_IDS` for SSO-linked users
 * and falls through to operator-seeded (email+password) accounts. See
 * ADR-0002 for the rationale; will tighten to spatie/laravel-permission
 * roles when UsersCharacters lands them.
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
                // Traffic-light health card for every backend (MariaDB,
                // Redis, Horizon, OpenSearch, InfluxDB, Neo4j). Lives on
                // the dashboard so operators see a dead backend the
                // moment they land, without having to navigate to the
                // dedicated /admin/system-status page.
                \App\Filament\Widgets\SystemStatusWidget::class,
            ])
            // Horizon lives in the sidebar as a plain nav item (not a Page)
            // because it ships its own Vue SPA that replaces Filament's
            // layout entirely. Rendering it inside a Filament panel iframe
            // would fight its router. Clicking this just full-navigates to
            // /horizon, which is gated on the same canAccessPanel() check —
            // see App\Providers\HorizonServiceProvider.
            ->navigationItems([
                NavigationItem::make('Horizon')
                    ->url('/horizon')
                    ->icon('heroicon-o-queue-list')
                    ->group('Monitoring')
                    ->sort(100),
            ])
            // User-menu (top-right dropdown) — two operator entries:
            //
            //   1. "Back to landing page" — always present. The Filament
            //      brand link in the topbar goes to `/admin`, which means
            //      there's no obvious way out of the admin area back to
            //      the marketing surface without typing a URL. This menu
            //      item closes that gap.
            //
            //   2. "Authorise EVE service character" — only when SSO is
            //      configured. Replaces the old "Log in with EVE Online"
            //      entry, which (in the admin context) was confusing
            //      because the operator is already logged in. The
            //      service-character flow is the right reason for an
            //      authenticated admin to round-trip through SSO again
            //      — to grant the app elevated scopes for ESI polling.
            //      See ADR-0002 § Token kinds + phase-2 amendment.
            ->userMenuItems(array_values(array_filter([
                MenuItem::make()
                    ->label('Back to landing page')
                    ->url(fn (): string => url('/'))
                    ->icon('heroicon-o-arrow-left-on-rectangle'),
                EveSsoClient::isConfigured()
                    ? MenuItem::make()
                        ->label('Authorise EVE service character')
                        ->url(fn (): string => route('filament.admin.pages.eve-service-character'))
                        ->icon('heroicon-o-key')
                    : null,
                // "Authorise donations character" — only when both SSO is
                // configured and a donations character ID is locked into
                // env. The page itself short-circuits with a "not
                // configured" panel without that env var, so showing the
                // menu entry would be a dead-end click. ADR-0002 §
                // phase-2 amendment.
                EveSsoClient::isConfigured() && is_int(config('eve.sso.donations.character_id'))
                    ? MenuItem::make()
                        ->label('Authorise donations character')
                        ->url(fn (): string => route('filament.admin.pages.eve-donations'))
                        ->icon('heroicon-o-banknotes')
                    : null,
            ])))
            // "Log in with EVE" button rendered under the default Filament
            // login form — only when the three required EVE_SSO_* env vars
            // are populated. Without that gate, clicking the button just
            // bounces the user back to /admin/login with an inline error,
            // which is a confusing dead-end for operators who haven't
            // wired up SSO yet (or whose config:cache is stale after a
            // .env edit). Hiding the button is the honest signal: "this
            // path isn't available on this deployment."
            //
            // Email+password stays live regardless so operator-seeded
            // accounts from `make filament-user` still work — SSO is
            // additive. Gate logic + admin allow-list live on the User
            // model and App\Services\Eve\Sso. See ADR-0002.
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): string => EveSsoClient::isConfigured()
                    ? view('filament.auth.eve-login-button')->render()
                    : '',
            )
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

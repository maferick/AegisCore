<?php

namespace App\Providers;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Horizon gate — piggybacks on the Filament admin auth.
 *
 * Policy: /horizon is an admin surface. Anyone who can see /admin can see
 * /horizon, and nobody else can. That means:
 *   - unauth hits           → redirect to /admin/login
 *   - authed non-admin      → 403
 *   - authed admin          → in
 *
 * Wiring:
 *
 *   1. `register()` overrides `config('horizon.middleware')` to the stock
 *      Laravel `web` stack plus `auth`. Horizon's upstream default is just
 *      `[Authorize::class]`, which 403s on failure — we want the `auth`
 *      middleware instead so unauthenticated hits get redirected to the login
 *      route (see bootstrap/app.php for `redirectGuestsTo`).
 *
 *   2. `gate()` delegates to the User model's `canAccessPanel()` check, which
 *      is the single source of truth for "is this an admin?" (see
 *      App\Models\User). When UsersCharacters wires spatie/laravel-permission
 *      and canAccessPanel() tightens to `$user->hasRole('alliance-admin')`,
 *      Horizon tightens with it automatically — no second policy to maintain.
 *
 * The previous phase-1 env knobs (HORIZON_UNPROTECTED, HORIZON_ALLOWED_EMAILS)
 * are gone. They were a stand-in for "we don't have auth yet"; now that the
 * Filament panel is the auth surface, deferring to it is both simpler and
 * correct.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function register(): void
    {
        parent::register();

        // Horizon's default middleware stack is just [Authorize::class], which
        // runs the `viewHorizon` gate and 403s on failure. We want the normal
        // web stack so sessions + redirects work, plus `auth` so unauth hits
        // bounce to login (see redirectGuestsTo in bootstrap/app.php) instead
        // of 403'ing at the gate.
        config(['horizon.middleware' => ['web', 'auth']]);
    }

    /**
     * Horizon dashboard access gate.
     *
     * Returns true iff the authenticated user passes the Filament admin
     * policy. This keeps "who sees Horizon?" and "who sees /admin?" in sync
     * by construction.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null): bool {
            if ($user === null) {
                return false;
            }

            return $user->canAccessPanel(Filament::getDefaultPanel());
        });
    }
}

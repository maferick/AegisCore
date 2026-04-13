<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Phase-1 Horizon gate.
 *
 * Horizon's upstream default is `app()->environment('local')`, which 403s
 * every non-local hit — including the one you get by clicking "Horizon" from
 * the landing page on a prod-deployed host. We don't yet have EVE SSO or an
 * alliance RBAC layer to do a proper "is this an admin?" check, so we
 * surface two env knobs and fail closed if neither is set:
 *
 *   HORIZON_UNPROTECTED=true
 *     Phase-1 escape hatch. Flips the gate open for everyone. Only use on a
 *     host that's behind an IP allowlist / VPN / HTTP basic auth at the
 *     nginx layer. Tighten before inviting anyone else in.
 *
 *   HORIZON_ALLOWED_EMAILS="a@b.com,c@d.com"
 *     CSV of user emails allowed through once we wire an auth guard. Until
 *     SSO lands, this only matters if you hand-seed a `users` row and hit
 *     /horizon while logged in — so it's here for when we flip the switch,
 *     not as a phase-1 production control.
 *
 * Once `UsersCharacters` has real auth + roles, delete these knobs and gate
 * on `$user->hasRole('alliance-admin')` (or whatever the RBAC name lands on).
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo(...);
        // Horizon::routeMailNotificationsTo(...);
        // Horizon::routeSlackNotificationsTo(...);
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            if (app()->environment('local')) {
                return true;
            }

            if (filter_var(env('HORIZON_UNPROTECTED'), FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }

            $allowed = array_filter(array_map(
                'trim',
                explode(',', (string) env('HORIZON_ALLOWED_EMAILS', ''))
            ));

            return $user !== null
                && property_exists($user, 'email')
                && in_array($user->email, $allowed, true);
        });
    }
}

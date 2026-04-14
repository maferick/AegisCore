<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Markets\Models\MarketWatchedLocation;
use App\Domains\UsersCharacters\Models\EveMarketToken;
use App\Services\Eve\Sso\EveSsoClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET /account/settings — donor-facing user surface (ADR-0004 § Filament / frontend split).
 *
 * Phase 5a (this version) is the minimum viable stub: linked
 * characters, donor status, and (for donors) the market-data CTA +
 * current token status. The structure picker + watched-locations
 * management lands as a Livewire component in a follow-up.
 *
 * Controller stays thin on purpose — structured view data gets
 * assembled here (not in the Blade template) so sensitive columns
 * (access_token / refresh_token) never enter the view scope by
 * construction. Same pattern as EveDonations::getViewData() in the
 * Filament admin surface.
 */
class AccountSettingsController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        \abort_if($user === null, 403);

        // The user model already exposes these safely; we just shape
        // the payload for the view so the Blade template doesn't
        // wire up multiple queries / decisions.
        $characters = $user->characters()
            ->orderBy('character_id')
            ->get(['character_id', 'name', 'corporation_id', 'alliance_id']);

        $isDonor = $user->isDonor();
        $ssoConfigured = EveSsoClient::isConfigured();

        // Market token status: scoped to the current user. Donor may
        // have 0 or 1 rows per character (one market token per EVE
        // character_id, enforced by the UNIQUE constraint on the
        // column). Cast into a minimal array — $hidden + the
        // ->toArray-safe shape keeps encrypted columns out of the
        // blade scope anyway, but doing it here is belt-and-braces.
        $marketTokens = $isDonor
            ? EveMarketToken::query()
                ->where('user_id', $user->id)
                ->orderBy('character_name')
                ->get()
                ->map(fn (EveMarketToken $t) => [
                    'character_id' => $t->character_id,
                    'character_name' => $t->character_name,
                    'scopes' => $t->scopes,
                    'expires_at' => $t->expires_at,
                    'is_fresh' => $t->isAccessTokenFresh(),
                    'has_market_scope' => $t->hasScope('esi-markets.structure_markets.v1'),
                    'updated_at' => $t->updated_at,
                ])
            : collect();

        // Watched structures owned by this donor. Read-only in this
        // stub view — the Livewire component lands with add/remove
        // actions in a follow-up.
        $watchedStructures = $isDonor
            ? MarketWatchedLocation::query()
                ->where('owner_user_id', $user->id)
                ->orderBy('name')
                ->get()
                ->map(fn (MarketWatchedLocation $l) => [
                    'id' => $l->id,
                    'location_type' => $l->location_type,
                    'location_id' => $l->location_id,
                    'region_id' => $l->region_id,
                    'name' => $l->name,
                    'enabled' => $l->enabled,
                    'last_polled_at' => $l->last_polled_at,
                    'consecutive_failure_count' => $l->consecutive_failure_count,
                    'disabled_reason' => $l->disabled_reason,
                ])
            : collect();

        return view('account.settings', [
            'user' => $user,
            'characters' => $characters,
            'is_donor' => $isDonor,
            'sso_configured' => $ssoConfigured,
            'market_tokens' => $marketTokens,
            'watched_structures' => $watchedStructures,
            'market_redirect_url' => $ssoConfigured ? route('auth.eve.market.redirect') : null,
        ]);
    }
}

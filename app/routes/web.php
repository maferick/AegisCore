<?php

use App\Http\Controllers\Auth\EveSsoController;
use App\Http\Controllers\Map\MapDataController;
use App\Http\Controllers\BattleTheaterOverrideController;
use App\Http\Controllers\PublicBattlesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('landing');
})->name('home');

// EVE SSO — OAuth2 PKCE against login.eveonline.com. Three flows share
// the same callback URL (so the registered CCP app only needs one
// redirect URI on file), with a session-stashed `flow` marker telling
// the callback which handler to dispatch:
//
//   /auth/eve                    login flow (publicData scope, no token storage)
//   /auth/eve/service-redirect   service flow (admin-only, elevated scopes,
//                                              tokens land in eve_service_tokens)
//   /auth/eve/donations-redirect donations flow (admin-only, single-character
//                                                lock, wallet-read scope only,
//                                                tokens land in
//                                                eve_donations_tokens)
//   /auth/eve/callback           routes to whichever flow's handler based on
//                                the session marker; bare GET with no marker
//                                falls through to the safer login handler.
//
// See App\Http\Controllers\Auth\EveSsoController + ADR-0002 § Token kinds
// (+ phase-2 amendment for the service + donations flow rationales).
Route::get('/auth/eve', [EveSsoController::class, 'redirect'])
    ->name('auth.eve.redirect');
Route::get('/auth/eve/callback', [EveSsoController::class, 'callback'])
    ->name('auth.eve.callback');
Route::get('/auth/eve/service-redirect', [EveSsoController::class, 'redirectAsService'])
    ->middleware('auth')
    ->name('auth.eve.service.redirect');
Route::get('/auth/eve/donations-redirect', [EveSsoController::class, 'redirectAsDonations'])
    ->middleware('auth')
    ->name('auth.eve.donations.redirect');
// Market flow — donor self-service (ADR-0004 § Live polling). Unlike
// the service + donations flows this one is NOT admin-locked; any
// authenticated user can initiate it, but the controller's donor
// gate + character-linkage check enforces who the token can belong
// to. See EveSsoController::redirectAsMarket() + finishMarketFlow().
Route::get('/auth/eve/war-stats', [EveSsoController::class, 'redirectAsWarStats'])
    ->name('auth.eve.war-stats');
Route::get('/auth/eve/market-redirect', [EveSsoController::class, 'redirectAsMarket'])
    ->middleware('auth')
    ->name('auth.eve.market.redirect');

// Sign-out from any page (currently: the landing page identity badge).
// POST so it can't be triggered by GET-prefetching or stray <a> clicks
// from outside the app — the form on the landing page CSRFs the request.
// Filament's own /admin sign-out keeps using the panel-scoped logout
// route; this one exists for the marketing/landing surface.
Route::post('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('home');
})->name('auth.logout');

// Legacy `/account/settings` — the account-settings UX was moved into
// the Filament Portal panel at `/portal/account-settings`
// (App\Filament\Portal\Pages\AccountSettings) which embeds the same
// Livewire `account.settings` component inside the portal chrome, so
// donors see a unified navigation instead of two disconnected pages.
// This 302 keeps any old bookmarks + inbound SSO-callback redirects
// from previous deploys working without a 404. All first-party
// callers now target `route('filament.portal.pages.account-settings')`
// directly.
Route::redirect('/account/settings', '/portal/account-settings')
    ->name('account.settings');

// Account / market hubs — ADR-0005 § Follow-ups #1. Registration and
// revoke stay on `/account/settings` (the ESI-backed structure picker
// lives there). This page is the dedicated multi-hub surface: richer
// list view of every hub the user may see via
// MarketHubAccessPolicy::visibleHubsFor + set/clear
// users.default_private_market_hub_id.
Route::get('/account/market-hubs', [\App\Http\Controllers\AccountMarketHubsController::class, 'show'])
    ->middleware('auth')
    ->name('account.market-hubs');

// Public map data endpoint for the EVE map renderer module.
//
// Unauthenticated by design — every byte returned here originates in
// CCP's published Static Data Export. Throttled to 60 req/min/IP as a
// sanity floor against accidental loops in browser code (the renderer
// only fetches once per mount, so this is well above any legitimate
// usage).
//
// Scopes: universe | region | constellation | subgraph (see
// App\Reference\Map\Enums\MapScope). Per-scope query-string args are
// validated by App\Http\Requests\Map\MapDataRequest.
Route::get('/internal/map/{scope}', MapDataController::class)
    ->middleware('throttle:60,1')
    ->name('map.data');

// Public battle reports — same rollup the Filament Portal page
// renders, minus coalition bloc labels (internal intel) and the
// portal nav chrome. Read-only; theater generation stays in the
// admin + scheduler path. Not auth-gated by design.
// Local proxy + cache for images.evetech.net assets. Serves the
// standard EVE imagery via /img/{kind}/{id}?size=N — first hit fetches
// from CCP, subsequent hits read from storage/app/eve-images. Browser
// cache headers (max-age=7d, immutable) so most icons never re-hit
// our app at all after the first load.
Route::get('/img/{kind}/{id}', [\App\Http\Controllers\EveImageProxyController::class, 'show'])
    ->where('kind', 'type|character|alliance|corporation')
    ->whereNumber('id')
    ->name('eve.image');

// Public war-report — landing index lists active conflicts as cards;
// /war-report/{conflict} opens the scoped 2-up report. Same chart/
// leaderboard rollup the authed Filament page renders, plain dark
// layout for killsineve.online. Read-only, auth-free.
Route::get('/war-report', [\App\Http\Controllers\PublicWarReportController::class, 'index'])
    ->name('public.war-report.index');
Route::get('/war-report/{conflict}', [\App\Http\Controllers\PublicWarReportController::class, 'show'])
    ->where('conflict', 'vs-(imperium|initiative)')
    ->name('public.war-report.show');
// Per-character effort page — separate session from /portal login.
Route::get('/war-report/{conflict}/me', [\App\Http\Controllers\WarEffortController::class, 'show'])
    ->where('conflict', 'vs-(imperium|initiative)')
    ->name('public.war-effort.show');
Route::post('/war-report/{conflict}/logout', [\App\Http\Controllers\WarEffortController::class, 'logout'])
    ->where('conflict', 'vs-(imperium|initiative)')
    ->name('public.war-effort.logout');

Route::get('/battles', [PublicBattlesController::class, 'index'])
    ->name('public.battles.index');
// Conflict-scoped battle list — same controller, restricted to
// theaters with war-attributable kms for that conflict.
Route::get('/battles/{conflict}', [PublicBattlesController::class, 'index'])
    ->where('conflict', 'vs-(imperium|initiative)')
    ->name('public.battles.scoped');
// Accept either numeric id (back-compat with old share links) or
// the stable public_slug. Route regex allows letters/digits/dashes
// so slugs like "9-gbpd-202604171300" resolve.
Route::get('/battles/{record}', [PublicBattlesController::class, 'show'])
    ->where('record', '[A-Za-z0-9\-]+')
    ->name('public.battles.show');

// Public killmail detail — same rollup the authed portal killmail
// page renders. All killmail fields are already public via
// zkillboard, so no gating needed.
Route::get('/kills/{record}', [\App\Http\Controllers\PublicKillmailsController::class, 'show'])
    ->whereNumber('record')
    ->name('public.kills.show');

// Side overrides — authed operators correct the auto-resolver's
// clustering. Per-theater, so the same alliance can be Side A in
// one battle and Side B in another. See ADR-0006 § 2 addendum.
Route::middleware('auth')->group(function () {
    Route::post('/portal/battles/{record}/overrides', [BattleTheaterOverrideController::class, 'store'])
        ->whereNumber('record')
        ->name('portal.battles.overrides.store');
    Route::delete('/portal/battles/{record}/overrides', [BattleTheaterOverrideController::class, 'destroy'])
        ->whereNumber('record')
        ->name('portal.battles.overrides.destroy');

    // Lazy-loaded activity map for a viewer's own character. Dashboard
    // skips the heavy BFS+titan compute on synchronous render;
    // browser fetches this partial after page load.
    Route::get('/portal/characters/{cid}/activity-map', [\App\Http\Controllers\Portal\CharacterActivityMapController::class, 'show'])
        ->whereNumber('cid')
        ->name('portal.characters.activity-map');

    // Phase 4.7F — shareable intelligence export viewer. Token-keyed
    // so authors can share a token with bloc peers without forwarding
    // the page URL. Bloc-scoped: viewer must belong to the same bloc
    // the artifact was created in.
    Route::get('/portal/intel/share/{token}', [\App\Http\Controllers\Portal\IntelExportShareController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{40}')
        ->name('portal.intel.share');
});

<?php

use App\Http\Controllers\Auth\EveSsoController;
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

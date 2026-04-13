<?php

use App\Http\Controllers\Auth\EveSsoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('landing');
});

// EVE SSO — OAuth2 PKCE login against login.eveonline.com. Both routes
// need web middleware so the session (which holds state + PKCE verifier
// between the two hops) is available; they're inside the default web
// group already via routes/web.php.
//
// See App\Http\Controllers\Auth\EveSsoController + ADR-0002.
Route::get('/auth/eve', [EveSsoController::class, 'redirect'])->name('auth.eve.redirect');
Route::get('/auth/eve/callback', [EveSsoController::class, 'callback'])->name('auth.eve.callback');

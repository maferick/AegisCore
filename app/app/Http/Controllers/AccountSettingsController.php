<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET /account/settings — donor-facing user surface.
 *
 * Thin wrapper that renders the outer page chrome; all interactivity
 * (market-data authorisation, structure picker, watched-structures
 * list + add/remove) lives in the `account.settings` Livewire
 * component.
 *
 * Kept as a plain controller (rather than a Livewire full-page
 * component) so the page chrome + `<style>` block lives in a
 * standalone Blade template — consistent with `landing.blade.php`
 * and easy to switch to a shared layout if/when we add one.
 */
class AccountSettingsController extends Controller
{
    public function show(Request $request): View
    {
        \abort_if($request->user() === null, 403);

        return view('account.settings');
    }
}

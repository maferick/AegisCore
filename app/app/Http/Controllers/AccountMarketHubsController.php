<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET /account/market-hubs — donor-facing multi-hub view.
 *
 * Thin wrapper rendering the outer page chrome; all interactivity
 * (hub list, set/clear default, collector + freeze status) lives in
 * the `account.market-hubs` Livewire component.
 *
 * Registration and revoke remain on `/account/settings` (ADR-0005
 * § Follow-ups #1): this page is the "richer multi-hub list + set
 * default" surface the ADR reserved for a dedicated route.
 */
class AccountMarketHubsController extends Controller
{
    public function show(Request $request): View
    {
        \abort_if($request->user() === null, 403);

        return view('account.market-hubs');
    }
}

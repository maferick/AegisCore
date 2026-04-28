<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Filament\Portal\Pages\WarReport;
use Illuminate\Contracts\View\View;

/**
 * Public war-report mirror at /war-report.
 *
 * Same view-data assembly as the authed Filament page (so charts +
 * leaderboards never drift between the two surfaces) — the page-class
 * is just used as a service here, not rendered inside the Filament
 * panel. The public blade wraps the shared body partial in a plain
 * dark layout instead of the Filament panel chrome.
 *
 * Cached aggressively at the data layer: WarReport::getViewData()
 * already serves from a 10-min Redis cache warmed by a 2-minute
 * scheduled task, so this endpoint is dominated by blade rendering.
 */
final class PublicWarReportController extends Controller
{
    public function __invoke(): View
    {
        $page = new WarReport();
        $data = $page->getViewData();
        return view('public.war-report', $data);
    }
}

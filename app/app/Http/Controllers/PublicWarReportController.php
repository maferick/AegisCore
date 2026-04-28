<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Filament\Portal\Pages\WarReport;
use App\Filament\Portal\Pages\WarReportIndex;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Public war-report mirror.
 *
 * Two routes feed in here:
 *   GET /war-report                    → conflict landing (cards)
 *   GET /war-report/{conflict}         → scoped 2-up report
 *
 * Same view-data assembly as the authed Filament pages (single source
 * of truth for charts + leaderboards). The public blades wrap the
 * shared body partial in a plain dark layout instead of the Filament
 * panel chrome.
 */
final class PublicWarReportController extends Controller
{
    public function index(): View
    {
        return view('public.war-report-index', WarReportIndex::buildIndexData());
    }

    public function show(Request $request, string $conflict): View
    {
        $page = new WarReport();
        $page->mount($conflict);
        $data = $page->getViewData();
        return view('public.war-report', $data);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\CounterIntel\Services\CounterIntelDossierService;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /admin/counter-intel/compare?cids=A,B,C — side-by-side dossier
 * comparison. Renders up to 4 pilots as vertically-stacked mini
 * dossiers so a director can eyeball which signals separate real
 * outliers from baseline.
 */
class CounterIntelCompare extends Page
{
    protected static string|BackedEnum|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

    // Distinct slug to avoid being swallowed by the sibling
    // CounterIntelDossier {character} wildcard.
    protected static ?string $slug = 'ci-compare';

    protected static ?string $title = 'Counter-Intel · Compare';

    protected string $view = 'filament.pages.counter-intel-compare';

    public function getViewData(): array
    {
        $cids = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) request()->query('cids', '')),
        )));
        $cids = array_slice($cids, 0, 4);
        if (! $cids) {
            return ['dossiers' => [], 'no_cids' => true];
        }
        $viewerBlocId = $this->resolveViewerBloc();
        if ($viewerBlocId === null) {
            return ['dossiers' => [], 'no_cids' => false, 'no_bloc' => true];
        }
        $svc = app(CounterIntelDossierService::class);
        $dossiers = [];
        foreach ($cids as $cid) {
            $dossiers[] = $svc->dossier($cid, $viewerBlocId);
        }
        return ['dossiers' => $dossiers, 'no_cids' => false, 'no_bloc' => false, 'viewer_bloc_id' => $viewerBlocId];
    }

    private function resolveViewerBloc(): ?int
    {
        $override = request()->query('bloc_id');
        if ($override !== null && ctype_digit((string) $override)) {
            return (int) $override;
        }
        $user = Auth::user();
        if ($user === null) return null;
        $char = $user->characters()->first();
        if ($char === null || ! $char->alliance_id) return null;
        $blocId = DB::table('coalition_entity_labels')
            ->where('entity_type', 'alliance')
            ->where('entity_id', $char->alliance_id)
            ->where('is_active', 1)
            ->value('bloc_id');
        return $blocId ? (int) $blocId : null;
    }
}

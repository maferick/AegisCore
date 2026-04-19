<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domains\CounterIntel\Services\CounterIntelDossierService;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /admin/counter-intel — operator triage surface.
 *
 * Per-bloc outlier dashboard. Rows link to /admin/counter-intel/{cid}.
 * Viewer bloc resolved from the logged-in user's character affiliation;
 * operator can override via query string (?bloc_id=N) when reviewing
 * for a different friendly coalition.
 */
class CounterIntelDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationLabel = 'Counter-Intel';

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?int $navigationSort = 40;

    protected static ?string $title = 'Counter-Intel · Review Priority';

    protected static ?string $slug = 'counter-intel';

    protected string $view = 'filament.admin.pages.counter-intel-dashboard';

    public ?string $bandFilter = null;

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $viewerBlocId = $this->resolveViewerBloc();
        if ($viewerBlocId === null) {
            return ['no_bloc' => true];
        }
        $svc = app(CounterIntelDossierService::class);
        $rows = $svc->outlierDashboard($viewerBlocId, limit: 100, bandFilter: $this->bandFilter ?: null);

        // Counts per band for header strip.
        $bandCounts = DB::table('ci_character_anomalies_rolling')
            ->where('viewer_bloc_id', $viewerBlocId)
            ->selectRaw('review_priority_band, COUNT(*) AS n')
            ->groupBy('review_priority_band')
            ->pluck('n', 'review_priority_band')
            ->all();

        $blocName = DB::table('coalition_blocs')->where('id', $viewerBlocId)->value('display_name') ?? "Bloc #{$viewerBlocId}";

        return [
            'no_bloc' => false,
            'viewer_bloc_id' => $viewerBlocId,
            'viewer_bloc_name' => $blocName,
            'rows' => $rows,
            'band_counts' => $bandCounts,
            'band_filter' => $this->bandFilter,
        ];
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

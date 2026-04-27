<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/intelligence/trust — operational trust dashboard.
 *
 * Reads system_trust_metrics and intel_feedback_events. Surfaces:
 * which intelligence surfaces are trustworthy, false positive rate
 * trend, analyst override frequency, suppression rate, narrative
 * correction rate.
 */
class TrustOverview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Trust overview';

    protected static string|UnitEnum|null $navigationGroup = 'Strategic';

    protected static ?int $navigationSort = 11;

    protected static ?string $title = 'Trust overview';

    protected static ?string $slug = 'intelligence/trust';

    protected string $view = 'filament.portal.pages.trust-overview';

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) {
            return ['no_bloc' => true];
        }

        $latestEnd = DB::table('system_trust_metrics')
            ->where('viewer_bloc_id', $blocId)
            ->max('window_end');

        $rows = DB::table('system_trust_metrics')
            ->where('viewer_bloc_id', $blocId)
            ->where('window_end', $latestEnd)
            ->orderByRaw("FIELD(trust_tier,'high','strong','adequate','low','untrusted')")
            ->orderByDesc('trust_score')
            ->get();

        // Per-surface freshness rollup: count freshness_state across each
        // bloc-scoped table so the trust dashboard can display freshness
        // alongside trust.
        $surfaceTables = [
            'alert' => 'strategic_alerts',
            'digest' => 'daily_operational_digest',
            'narrative' => 'incident_narratives',
            'incident' => 'operational_incidents',
            'corridor' => 'operational_corridors',
            'alliance_profile' => 'alliance_operational_profiles',
            'threat_surface' => 'system_threat_surface',
        ];
        $freshness = [];
        foreach ($surfaceTables as $surface => $table) {
            $tally = DB::table($table)
                ->where('viewer_bloc_id', $blocId)
                ->groupBy('freshness_state')
                ->selectRaw('freshness_state, COUNT(*) AS n')
                ->pluck('n', 'freshness_state')
                ->all();
            $freshness[$surface] = $tally;
        }

        $feedback = DB::table('intel_feedback_events')
            ->where('viewer_bloc_id', $blocId)
            ->where('created_at', '>=', now()->subDays(60))
            ->groupBy('surface', 'feedback_kind')
            ->selectRaw('surface, feedback_kind, COUNT(*) AS n')
            ->orderBy('surface')
            ->get();

        $alertSummary = DB::table('strategic_alerts')
            ->where('viewer_bloc_id', $blocId)
            ->groupBy('analyst_status')
            ->selectRaw('analyst_status, COUNT(*) AS n')
            ->pluck('n', 'analyst_status')
            ->all();

        $verifiedSummary = DB::table('verified_intelligence_items')
            ->where('viewer_bloc_id', $blocId)
            ->groupBy('item_kind')
            ->selectRaw('item_kind, COUNT(*) AS n')
            ->pluck('n', 'item_kind')
            ->all();

        $suppressionRules = DB::table('intel_alert_suppression_rules')
            ->where('viewer_bloc_id', $blocId)
            ->where(function ($q) { $q->whereNull('active_until')->orWhere('active_until', '>=', now()); })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return [
            'no_bloc' => false,
            'latest_end' => $latestEnd,
            'rows' => $rows,
            'feedback' => $feedback,
            'alert_summary' => $alertSummary,
            'verified_summary' => $verifiedSummary,
            'suppression_rules' => $suppressionRules,
            'freshness' => $freshness,
        ];
    }

    private function resolveViewerBlocId(): ?int
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

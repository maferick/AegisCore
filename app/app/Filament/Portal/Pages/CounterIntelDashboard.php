<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/counter-intel — bloc-scoped Counter-Intel overview.
 *
 * Surfaces top review-priority candidates, recent escalations, signal
 * band distribution, and watchlist quick stats in one place. Read-only
 * dashboard — every action (add to watchlist, transition status,
 * inspect evidence) lives on the per-character lookup card.
 *
 * Cards/blocks rendered from already-materialised tables. No expensive
 * compute on this page.
 */
class CounterIntelDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationLabel = 'Counter-Intel Overview';

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Counter-Intel Overview';

    protected static ?string $slug = 'counter-intel';

    protected string $view = 'filament.portal.pages.counter-intel-dashboard';

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) {
            return ['no_bloc' => true];
        }

        $blocName = DB::table('coalition_blocs')->where('id', $blocId)->value('display_name')
            ?? "Bloc #{$blocId}";

        // Top candidates by latest band — pull from ci_render_diagnostics
        // (the audit table) joined to esi_entity_names + watchlist
        // status. Band priority sort: critical > high > elevated > note_only.
        $topRows = DB::select(<<<'SQL'
            SELECT d.character_id, d.rendered_band, d.confidence, d.flag_count, d.note_count,
                   d.declared_in_bloc, d.rendered_at, en.name AS character_name,
                   w.status AS watchlist_status, w.id AS watchlist_id
              FROM ci_render_diagnostics d
              LEFT JOIN esi_entity_names en
                ON en.entity_id = d.character_id AND en.category = 'character'
              LEFT JOIN ci_watchlist_entries w
                ON w.character_id = d.character_id AND w.viewer_bloc_id = d.viewer_bloc_id
             WHERE d.viewer_bloc_id = ?
               AND d.rendered_on >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
               AND d.rendered_band IN ('critical', 'high', 'elevated')
             ORDER BY FIELD(d.rendered_band, 'critical', 'high', 'elevated'),
                      d.flag_count DESC, d.note_count DESC, d.rendered_at DESC
             LIMIT 50
        SQL, [$blocId]);

        // Signal-band distribution (last 24h diagnostics).
        $bandDist = DB::table('ci_render_diagnostics')
            ->where('viewer_bloc_id', $blocId)
            ->where('rendered_at', '>=', now()->subDay())
            ->groupBy('rendered_band')
            ->select('rendered_band', DB::raw('COUNT(*) AS n'))
            ->pluck('n', 'rendered_band')
            ->all();

        // Recent escalations (last 7 days).
        $escalations = DB::select(<<<'SQL'
            SELECT w.character_id, w.status, w.last_status_change_at, w.reason,
                   en.name AS character_name, u.name AS changed_by_name
              FROM ci_watchlist_entries w
              LEFT JOIN esi_entity_names en
                ON en.entity_id = w.character_id AND en.category = 'character'
              LEFT JOIN users u
                ON u.id = w.last_status_change_by
             WHERE w.viewer_bloc_id = ?
               AND w.status = 'escalated'
               AND w.last_status_change_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY w.last_status_change_at DESC
             LIMIT 20
        SQL, [$blocId]);

        // Watchlist counts per status.
        $watchlistCounts = DB::table('ci_watchlist_entries')
            ->where('viewer_bloc_id', $blocId)
            ->groupBy('status')
            ->select('status', DB::raw('COUNT(*) AS n'))
            ->pluck('n', 'status')
            ->all();

        // Top hostile triangle clusters in the bloc — characters with
        // the most members in their hostile triangle.
        $topTriangles = DB::select(<<<'SQL'
            SELECT t.character_id, t.triangle_size, t.shared_battle_days, t.weight,
                   en.name AS character_name
              FROM ci_hostile_triangulation t
              LEFT JOIN esi_entity_names en
                ON en.entity_id = t.character_id AND en.category = 'character'
             WHERE t.viewer_bloc_id = ?
             ORDER BY t.triangle_size DESC, t.shared_battle_days DESC
             LIMIT 10
        SQL, [$blocId]);

        // Signal-type counts (last 24h) — which reasons fire most.
        $reasonCounts = $this->computeReasonCounts($blocId);

        // Phase 4 — recent operational timeline (last 24h).
        $recentTimeline = DB::table('operational_timeline_events')
            ->where('viewer_bloc_id', $blocId)
            ->where('event_timestamp', '>=', now()->subDay())
            ->orderByDesc('event_timestamp')
            ->limit(20)
            ->get([
                'timeline_type', 'event_timestamp', 'source_listener',
                'solar_system_name', 'event_summary', 'confidence',
            ]);

        // Active fleet windows in the last 6h.
        $activeFleets = DB::table('fleet_presence_windows')
            ->where('viewer_bloc_id', $blocId)
            ->where('end_at', '>=', now()->subHours(6))
            ->orderByDesc('end_at')
            ->limit(20)
            ->get([
                'character_name', 'fleet_channel', 'start_at', 'end_at',
                'duration_minutes', 'derived_role', 'killmail_count',
                'spoken_messages', 'confidence',
            ]);

        return [
            'no_bloc' => false,
            'viewer_bloc_id' => $blocId,
            'viewer_bloc_name' => $blocName,
            'top_rows' => $topRows,
            'band_dist' => $bandDist,
            'escalations' => $escalations,
            'watchlist_counts' => $watchlistCounts,
            'top_triangles' => $topTriangles,
            'reason_counts' => $reasonCounts,
            'recent_timeline' => $recentTimeline,
            'active_fleets' => $activeFleets,
        ];
    }

    /**
     * Decode the rendered_signals_json blobs from the last 24h and
     * count by reason_code. Rough — done in PHP rather than SQL since
     * the column is JSON. Limited to 1000 most recent rows so this
     * stays cheap.
     *
     * @return array<string, int>
     */
    private function computeReasonCounts(int $blocId): array
    {
        $rows = DB::table('ci_render_diagnostics')
            ->where('viewer_bloc_id', $blocId)
            ->where('rendered_at', '>=', now()->subDay())
            ->whereIn('rendered_band', ['critical', 'high', 'elevated', 'note_only'])
            ->orderByDesc('rendered_at')
            ->limit(1000)
            ->pluck('rendered_signals_json')
            ->all();
        $counts = [];
        foreach ($rows as $json) {
            $decoded = json_decode((string) $json, true);
            if (! is_array($decoded)) continue;
            foreach ($decoded as $sig) {
                if (($sig['severity'] ?? '') === 'suppressed') continue;
                $code = $sig['reason_code'] ?? $sig['key'] ?? null;
                if ($code === null) continue;
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }
        arsort($counts);
        return $counts;
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

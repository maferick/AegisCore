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

    protected static string|UnitEnum|null $navigationGroup = 'Daily ops';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Counter-Intel Overview';

    protected static ?string $slug = 'counter-intel';

    protected string $view = 'filament.portal.pages.counter-intel-dashboard';

    // Re-promoted to sidebar primary 2026-04-28 — operator
    // prefers the Overview's compact KPI tiles + tabular review
    // queue + side panels over the Command page's long expandable
    // card stream. Command stays at /portal/counter-intel/command
    // as the deep-dive surface; Overview links to it.

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) {
            return ['no_bloc' => true];
        }

        $blocName = DB::table('coalition_blocs')->where('id', $blocId)->value('display_name')
            ?? "Bloc #{$blocId}";

        // Top candidates — single source of truth is the fused
        // hypothesis table written by phase18-hypothesis-fusion.
        // The Command Surface (/portal/counter-intel/command) is
        // the primary; this Overview page surfaces the same top
        // entries so rankings agree across surfaces.
        //
        // Map fusion rows back to the Overview's existing column
        // shape so the blade render stays the same.
        $topRows = DB::select(<<<'SQL'
            SELECT h.primary_character_id AS character_id,
                   CASE h.severity
                     WHEN 'critical' THEN 'critical'
                     WHEN 'elevated' THEN 'high'
                     WHEN 'watch'    THEN 'elevated'
                     ELSE 'note_only'
                   END AS rendered_band,
                   h.confidence,
                   h.evidence_count AS flag_count,
                   h.corroboration_count AS note_count,
                   1 AS declared_in_bloc,
                   h.last_strengthened_at AS rendered_at,
                   en.name AS character_name,
                   w.status AS watchlist_status, w.id AS watchlist_id
              FROM counter_intel_hypotheses h
              LEFT JOIN esi_entity_names en
                ON en.entity_id = h.primary_character_id AND en.category = 'character'
              LEFT JOIN ci_watchlist_entries w
                ON w.character_id = h.primary_character_id AND w.viewer_bloc_id = h.viewer_bloc_id
             WHERE h.viewer_bloc_id = ?
               AND h.status <> 'archived'
               AND h.confidence IN ('high', 'medium', 'confirmed')
             ORDER BY FIELD(h.confidence, 'confirmed', 'high', 'medium'),
                      FIELD(h.severity, 'critical', 'elevated', 'watch', 'info'),
                      h.suspicion_score DESC,
                      h.last_strengthened_at DESC
             LIMIT 25
        SQL, [$blocId]);

        // Signal-band distribution — same fusion source as the top
        // candidates so the Overview's KPI counters match what the
        // Command Surface shows. Maps fusion severity → legacy
        // band names so the blade's existing tile colours still
        // line up.
        $sevToBand = [
            'critical' => 'critical',
            'elevated' => 'high',
            'watch'    => 'elevated',
            'info'     => 'note_only',
        ];
        $bandDist = ['critical' => 0, 'high' => 0, 'elevated' => 0, 'note_only' => 0, 'clean' => 0];
        $rawSev = DB::table('counter_intel_hypotheses')
            ->where('viewer_bloc_id', $blocId)
            ->where('status', '<>', 'archived')
            ->groupBy('severity')
            ->select('severity', DB::raw('COUNT(*) AS n'))
            ->pluck('n', 'severity')
            ->all();
        foreach ($rawSev as $sev => $n) {
            $b = $sevToBand[$sev] ?? 'note_only';
            $bandDist[$b] = ($bandDist[$b] ?? 0) + (int) $n;
        }

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

        // Internal pilots by hostile-cluster exposure. The character_id
        // is a bloc-internal pilot; the triangulation rows around them
        // describe distinct hostile pilots they've engaged. The
        // alliance_name + review_priority_band columns make it
        // unambiguous that these are OUR pilots being scored, not
        // hostile actors in the watchlist sense.
        $topTriangles = DB::select(<<<'SQL'
            SELECT t.character_id, t.triangle_size, t.shared_battle_days, t.weight,
                   en.name AS character_name,
                   alli_en.name AS alliance_name,
                   cah.alliance_id AS alliance_id,
                   a.review_priority_band, a.review_priority_score
              FROM ci_hostile_triangulation t
              LEFT JOIN esi_entity_names en
                ON en.entity_id = t.character_id AND en.category = 'character'
              LEFT JOIN character_corporation_history cch
                ON cch.character_id = t.character_id
               AND cch.is_deleted = 0
               AND cch.end_date IS NULL
              LEFT JOIN corporation_alliance_history cah
                ON cah.corporation_id = cch.corporation_id
               AND cah.start_date <= NOW()
               AND (cah.end_date IS NULL OR cah.end_date > NOW())
              LEFT JOIN esi_entity_names alli_en
                ON alli_en.entity_id = cah.alliance_id AND alli_en.category = 'alliance'
              LEFT JOIN (
                  SELECT a.character_id, a.viewer_bloc_id,
                         a.review_priority_band, a.review_priority_score
                    FROM ci_character_anomalies_rolling a
                    JOIN (
                        SELECT character_id, viewer_bloc_id, MAX(window_end_date) AS mx
                          FROM ci_character_anomalies_rolling
                         GROUP BY character_id, viewer_bloc_id
                    ) m
                      ON m.character_id = a.character_id
                     AND m.viewer_bloc_id = a.viewer_bloc_id
                     AND m.mx = a.window_end_date
              ) a ON a.character_id = t.character_id AND a.viewer_bloc_id = t.viewer_bloc_id
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

        // One-line verdict — research-distilled "scan in seconds"
        // affordance. Operator should see the answer to "what
        // needs my attention?" before reading any tile or row.
        $critical  = (int) ($bandDist['critical'] ?? 0);
        $high      = (int) ($bandDist['high']     ?? 0);
        $elevated  = (int) ($bandDist['elevated'] ?? 0);
        $details = [];
        if ($critical > 0) $details[] = "{$critical} critical";
        if ($high > 0)     $details[] = "{$high} high-confidence";
        if ($elevated > 0) $details[] = "{$elevated} elevated";
        $totalRev = $critical + $high + $elevated;
        if ($totalRev === 0) {
            $verdict = ['severity' => 'info', 'headline' => 'No high-priority hypotheses', 'details' => []];
        } elseif ($critical > 0) {
            $verdict = ['severity' => 'critical', 'headline' => 'Critical-confidence hypotheses warrant review', 'details' => $details];
        } elseif ($high > 0) {
            $verdict = ['severity' => 'elevated', 'headline' => 'High-confidence hypotheses warrant review', 'details' => $details];
        } else {
            $verdict = ['severity' => 'warning', 'headline' => 'Elevated-band hypotheses present', 'details' => $details];
        }

        return [
            'no_bloc' => false,
            'viewer_bloc_id' => $blocId,
            'viewer_bloc_name' => $blocName,
            'verdict' => $verdict,
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

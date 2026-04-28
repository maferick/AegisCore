<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/war-report — WinterCo vs Goonswarm + The Initiative., from
 * 2026-04-02 onward (ongoing).
 *
 * Pure descriptive surface: every war-attributable killmail (victim on
 * one side, ≥1 attacker on the opposing side), 3-column losses layout,
 * system hotspots, and an upwell-structure kill timeline.
 *
 * Bloc/alliance scope is hard-coded — this is a single-conflict page,
 * not a configurable matchup builder. If the conflict spec changes,
 * change the constants below.
 */
class WarReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-fire';

    protected static ?string $navigationLabel = 'War Report';

    protected static string|UnitEnum|null $navigationGroup = 'Strategic';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'War Report — WinterCo vs Goons + Init';

    protected static ?string $slug = 'war-report';

    protected string $view = 'filament.portal.pages.war-report';

    /** Conflict floor — every query is bounded by this. */
    private const string WAR_START = '2026-04-02 00:00:00';

    /** WinterCo bloc id (coalition_blocs.id). */
    private const int WINTERCO_BLOC_ID = 1;

    /** Goonswarm Federation alliance id. */
    private const int GOONS_ALLIANCE_ID = 1354830081;

    /** The Initiative. alliance id. */
    private const int INITIATIVE_ALLIANCE_ID = 1900696668;

    /** Default number of days to render expanded per column. */
    private const int DEFAULT_DAYS = 7;

    public string $sinceDays = '';

    public function mount(): void
    {
        $this->sinceDays = (string) request()->query('days', (string) self::DEFAULT_DAYS);
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $start = self::WAR_START;
        $now = now();
        $totalDays = max(1, (int) Carbon::parse($start)->diffInDays($now));

        $days = max(1, min(60, (int) ($this->sinceDays !== '' ? $this->sinceDays : self::DEFAULT_DAYS)));
        $windowStart = $now->copy()->subDays($days - 1)->startOfDay();
        $windowStartStr = $windowStart->format('Y-m-d H:i:s');

        $wcAlly = DB::table('coalition_entity_labels')
            ->where('bloc_id', self::WINTERCO_BLOC_ID)
            ->where('entity_type', 'alliance')
            ->where('is_active', 1)
            ->pluck('entity_id')->all();
        $wcAlly = array_map('intval', $wcAlly);

        $hostile = [self::GOONS_ALLIANCE_ID, self::INITIATIVE_ALLIANCE_ID];

        // Side-level totals + ISK across the entire conflict (not just
        // the rendered window) — used in the hero banner and tile row.
        $totals = [
            'wc'   => $this->sideLossTotals($start, $wcAlly, $hostile),
            'goon' => $this->sideLossTotals($start, [self::GOONS_ALLIANCE_ID], $wcAlly),
            'init' => $this->sideLossTotals($start, [self::INITIATIVE_ALLIANCE_ID], $wcAlly),
        ];

        // Per-column killmail rows within the rendered window. Each
        // row carries enough metadata for the day-bucketed list to
        // render without a second query.
        $columns = [
            'wc'   => $this->fetchLossesForVictims($windowStartStr, $wcAlly, $hostile),
            'goon' => $this->fetchLossesForVictims($windowStartStr, [self::GOONS_ALLIANCE_ID], $wcAlly),
            'init' => $this->fetchLossesForVictims($windowStartStr, [self::INITIATIVE_ALLIANCE_ID], $wcAlly),
        ];

        $hotspots = $this->systemHotspots($start, $wcAlly, $hostile);
        $structures = $this->upwellStructureTimeline($start, $wcAlly, $hostile);

        return [
            'war_start' => $start,
            'window_start' => $windowStartStr,
            'days' => $days,
            'total_days' => $totalDays,
            'totals' => $totals,
            'columns' => $columns,
            'hotspots' => $hotspots,
            'structures' => $structures,
            'wc_alliance_count' => count($wcAlly),
        ];
    }

    /**
     * @param  list<int>  $victimAlliances
     * @param  list<int>  $hostileAlliances
     * @return array{kms:int, isk:float}
     */
    private function sideLossTotals(string $start, array $victimAlliances, array $hostileAlliances): array
    {
        if ($victimAlliances === [] || $hostileAlliances === []) {
            return ['kms' => 0, 'isk' => 0.0];
        }
        $row = DB::selectOne("
            SELECT COUNT(DISTINCT k.killmail_id) AS n,
                   COALESCE(SUM(k.total_value), 0) AS isk
            FROM killmails k
            JOIN killmail_attackers a ON a.killmail_id = k.killmail_id
            WHERE k.killed_at >= ?
              AND k.victim_alliance_id IN (" . implode(',', $victimAlliances) . ")
              AND a.alliance_id IN (" . implode(',', $hostileAlliances) . ")
        ", [$start]);
        return ['kms' => (int) $row->n, 'isk' => (float) $row->isk];
    }

    /**
     * Every war-attributable loss for the given victim-alliance set
     * since $windowStart, ordered newest-first, day-bucketed.
     *
     * @param  list<int>  $victimAlliances
     * @param  list<int>  $hostileAlliances
     * @return array<string, list<object>>  bucketed by 'YYYY-MM-DD'
     */
    private function fetchLossesForVictims(string $windowStart, array $victimAlliances, array $hostileAlliances): array
    {
        if ($victimAlliances === [] || $hostileAlliances === []) {
            return [];
        }
        $rows = DB::select("
            SELECT
                k.killmail_id,
                k.killed_at,
                k.solar_system_id,
                ss.name AS system_name,
                k.victim_character_id,
                k.victim_corporation_id,
                k.victim_alliance_id,
                k.victim_ship_type_id,
                k.victim_ship_type_name,
                k.victim_ship_category_id,
                k.total_value,
                vname.name AS victim_name,
                aname.name AS victim_alliance_name,
                fb.character_id AS fb_char_id,
                fb_n.name AS fb_char_name,
                fb.alliance_id AS fb_alliance_id,
                fb_an.name AS fb_alliance_name,
                fb.ship_type_id AS fb_ship_type_id,
                fb_st.name AS fb_ship_type_name
            FROM killmails k
            JOIN ref_solar_systems ss ON ss.id = k.solar_system_id
            LEFT JOIN esi_entity_names vname  ON vname.entity_id = k.victim_character_id AND vname.category = 'character'
            LEFT JOIN esi_entity_names aname  ON aname.entity_id = k.victim_alliance_id AND aname.category = 'alliance'
            LEFT JOIN killmail_attackers fb   ON fb.killmail_id = k.killmail_id AND fb.is_final_blow = 1
            LEFT JOIN esi_entity_names fb_n   ON fb_n.entity_id = fb.character_id AND fb_n.category = 'character'
            LEFT JOIN esi_entity_names fb_an  ON fb_an.entity_id = fb.alliance_id AND fb_an.category = 'alliance'
            LEFT JOIN ref_item_types fb_st    ON fb_st.id = fb.ship_type_id
            WHERE k.killed_at >= ?
              AND k.victim_alliance_id IN (" . implode(',', $victimAlliances) . ")
              AND EXISTS (
                  SELECT 1 FROM killmail_attackers a
                  WHERE a.killmail_id = k.killmail_id
                    AND a.alliance_id IN (" . implode(',', $hostileAlliances) . ")
              )
            ORDER BY k.killed_at DESC
            LIMIT 5000
        ", [$windowStart]);

        $bucketed = [];
        foreach ($rows as $r) {
            $day = substr((string) $r->killed_at, 0, 10);
            $bucketed[$day] ??= [];
            $bucketed[$day][] = $r;
        }
        return $bucketed;
    }

    /**
     * Top systems by war-attributable killmail count over the entire
     * conflict window.
     *
     * @param  list<int>  $wcAlly
     * @param  list<int>  $hostile
     * @return list<object>
     */
    private function systemHotspots(string $start, array $wcAlly, array $hostile): array
    {
        if ($wcAlly === [] || $hostile === []) {
            return [];
        }
        $wcStr = implode(',', $wcAlly);
        $hStr = implode(',', $hostile);
        return DB::select("
            SELECT k.solar_system_id, ss.name AS system_name, ss.security_status,
                   COUNT(DISTINCT k.killmail_id) AS km_count,
                   COALESCE(SUM(k.total_value), 0) AS isk_destroyed,
                   MAX(k.killed_at) AS last_km
            FROM killmails k
            JOIN ref_solar_systems ss ON ss.id = k.solar_system_id
            WHERE k.killed_at >= ?
              AND (
                  (k.victim_alliance_id IN ($wcStr) AND EXISTS(SELECT 1 FROM killmail_attackers a WHERE a.killmail_id = k.killmail_id AND a.alliance_id IN ($hStr)))
                  OR
                  (k.victim_alliance_id IN ($hStr) AND EXISTS(SELECT 1 FROM killmail_attackers a WHERE a.killmail_id = k.killmail_id AND a.alliance_id IN ($wcStr)))
              )
            GROUP BY k.solar_system_id, ss.name, ss.security_status
            ORDER BY km_count DESC
            LIMIT 20
        ", [$start]);
    }

    /**
     * Every upwell structure killmail in the conflict (category 65 =
     * Structure). Returned newest-first; the blade groups by side.
     *
     * @param  list<int>  $wcAlly
     * @param  list<int>  $hostile
     * @return list<object>
     */
    private function upwellStructureTimeline(string $start, array $wcAlly, array $hostile): array
    {
        if ($wcAlly === [] || $hostile === []) {
            return [];
        }
        $wcStr = implode(',', $wcAlly);
        $hStr = implode(',', $hostile);
        return DB::select("
            SELECT k.killmail_id, k.killed_at, k.solar_system_id, ss.name AS system_name,
                   k.victim_corporation_id, k.victim_alliance_id,
                   k.victim_ship_type_id, k.victim_ship_type_name, k.victim_ship_group_name,
                   k.total_value,
                   vc.name AS victim_corp_name, va.name AS victim_alliance_name,
                   CASE
                       WHEN k.victim_alliance_id IN ($wcStr) THEN 'wc'
                       WHEN k.victim_alliance_id IN ($hStr) THEN 'hostile'
                       ELSE 'other'
                   END AS side
            FROM killmails k
            JOIN ref_solar_systems ss ON ss.id = k.solar_system_id
            LEFT JOIN esi_entity_names vc ON vc.entity_id = k.victim_corporation_id AND vc.category = 'corporation'
            LEFT JOIN esi_entity_names va ON va.entity_id = k.victim_alliance_id AND va.category = 'alliance'
            WHERE k.killed_at >= ?
              AND k.victim_ship_category_id = 65
              AND (
                  (k.victim_alliance_id IN ($wcStr) AND EXISTS(SELECT 1 FROM killmail_attackers a WHERE a.killmail_id = k.killmail_id AND a.alliance_id IN ($hStr)))
                  OR
                  (k.victim_alliance_id IN ($hStr) AND EXISTS(SELECT 1 FROM killmail_attackers a WHERE a.killmail_id = k.killmail_id AND a.alliance_id IN ($wcStr)))
              )
            ORDER BY k.killed_at DESC
        ", [$start]);
    }
}

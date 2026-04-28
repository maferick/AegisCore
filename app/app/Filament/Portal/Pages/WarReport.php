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

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $start = self::WAR_START;
        $now = now();
        $totalDays = max(1, (int) Carbon::parse($start)->diffInDays($now));

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

        // Aggregated rollups per side — daily activity, ship-group
        // breakdown, top alliances, top systems. These power the
        // histograms / horizontal bar charts on the page; raw
        // killmail lists are limited to a small "recent" strip.
        $rollups = [
            'wc'   => $this->sideRollups($start, $wcAlly, $hostile),
            'goon' => $this->sideRollups($start, [self::GOONS_ALLIANCE_ID], $wcAlly),
            'init' => $this->sideRollups($start, [self::INITIATIVE_ALLIANCE_ID], $wcAlly),
        ];

        // Compact "recent" feed per side — last 15 mails so the
        // operator can eyeball the latest activity without scrolling
        // a 5k-row list.
        $recent = [
            'wc'   => $this->recentLosses($wcAlly, $hostile, 15),
            'goon' => $this->recentLosses([self::GOONS_ALLIANCE_ID], $wcAlly, 15),
            'init' => $this->recentLosses([self::INITIATIVE_ALLIANCE_ID], $wcAlly, 15),
        ];

        $hotspots = $this->systemHotspots($start, $wcAlly, $hostile);
        $structures = $this->upwellStructureTimeline($start, $wcAlly, $hostile);
        $topImplantPods = $this->topImplantPods($start, $wcAlly, $hostile);

        return [
            'war_start' => $start,
            'total_days' => $totalDays,
            'totals' => $totals,
            'rollups' => $rollups,
            'recent' => $recent,
            'hotspots' => $hotspots,
            'structures' => $structures,
            'top_implant_pods' => $topImplantPods,
            'wc_alliance_count' => count($wcAlly),
        ];
    }

    /**
     * Top-N pod kills in the conflict by total_value. The valuation
     * pipeline rolls implant ISK into killmails.total_value, so the
     * highest pod values directly read out as implant losses. Pods
     * with total_value=0 are clean clones (ESI returns items: []
     * for those) and are intentionally excluded.
     *
     * @param  list<int>  $wcAlly
     * @param  list<int>  $hostile
     * @return list<object>
     */
    private function topImplantPods(string $start, array $wcAlly, array $hostile): array
    {
        if ($wcAlly === [] || $hostile === []) {
            return [];
        }
        $wcStr = implode(',', $wcAlly);
        $hStr = implode(',', $hostile);
        return DB::select("
            SELECT k.killmail_id, k.killed_at, k.total_value,
                   k.solar_system_id, ss.name AS system_name,
                   k.victim_character_id, k.victim_alliance_id,
                   en.name AS victim_name, an.name AS victim_alliance_name,
                   CASE
                       WHEN k.victim_alliance_id IN ($wcStr) THEN 'wc'
                       WHEN k.victim_alliance_id IN ($hStr) THEN 'hostile'
                       ELSE 'other'
                   END AS side
            FROM killmails k
            JOIN ref_solar_systems ss ON ss.id = k.solar_system_id
            LEFT JOIN esi_entity_names en ON en.entity_id = k.victim_character_id AND en.category = 'character'
            LEFT JOIN esi_entity_names an ON an.entity_id = k.victim_alliance_id AND an.category = 'alliance'
            WHERE k.killed_at >= ?
              AND k.victim_ship_type_id IN (670, 33328)
              AND k.total_value > 0
              AND (
                  (k.victim_alliance_id IN ($wcStr) AND EXISTS(SELECT 1 FROM killmail_attackers a WHERE a.killmail_id = k.killmail_id AND a.alliance_id IN ($hStr)))
                  OR
                  (k.victim_alliance_id IN ($hStr) AND EXISTS(SELECT 1 FROM killmail_attackers a WHERE a.killmail_id = k.killmail_id AND a.alliance_id IN ($wcStr)))
              )
            ORDER BY k.total_value DESC
            LIMIT 10
        ", [$start]);
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
     * Aggregated rollups for one side. Each rollup powers a chart on
     * the war-report page. Conflict-wide window — caller passes the
     * conflict floor.
     *
     * Returns:
     *   - daily         list of { day, kms, isk } across the conflict
     *   - ship_groups   top-12 ship groups lost (count + isk)
     *   - alliances     top-10 victim alliances within the side bloc
     *   - systems       top-10 systems where this side died
     *   - hour_of_day   24-bucket histogram of killmail count
     *
     * @param  list<int>  $victimAlliances
     * @param  list<int>  $hostileAlliances
     * @return array<string, mixed>
     */
    private function sideRollups(string $start, array $victimAlliances, array $hostileAlliances): array
    {
        if ($victimAlliances === [] || $hostileAlliances === []) {
            return ['daily' => [], 'ship_groups' => [], 'alliances' => [], 'systems' => [], 'hour_of_day' => []];
        }
        $vStr = implode(',', $victimAlliances);
        $hStr = implode(',', $hostileAlliances);
        $where = "
            WHERE k.killed_at >= ?
              AND k.victim_alliance_id IN ($vStr)
              AND EXISTS (
                  SELECT 1 FROM killmail_attackers a
                  WHERE a.killmail_id = k.killmail_id
                    AND a.alliance_id IN ($hStr)
              )
        ";

        $daily = DB::select("
            SELECT DATE(k.killed_at) AS day, COUNT(*) AS kms, COALESCE(SUM(k.total_value),0) AS isk
            FROM killmails k
            $where
            GROUP BY DATE(k.killed_at)
            ORDER BY day ASC
        ", [$start]);

        $shipGroups = DB::select("
            SELECT COALESCE(NULLIF(k.victim_ship_group_name,''), 'Unknown') AS label,
                   COUNT(*) AS kms,
                   COALESCE(SUM(k.total_value),0) AS isk
            FROM killmails k
            $where
            GROUP BY label
            ORDER BY kms DESC
            LIMIT 12
        ", [$start]);

        $alliances = DB::select("
            SELECT k.victim_alliance_id AS id,
                   COALESCE(en.name, CONCAT('#', k.victim_alliance_id)) AS label,
                   COUNT(*) AS kms,
                   COALESCE(SUM(k.total_value),0) AS isk
            FROM killmails k
            LEFT JOIN esi_entity_names en ON en.entity_id = k.victim_alliance_id AND en.category = 'alliance'
            $where
            GROUP BY k.victim_alliance_id, en.name
            ORDER BY kms DESC
            LIMIT 10
        ", [$start]);

        $systems = DB::select("
            SELECT ss.id, ss.name AS label, ss.security_status,
                   COUNT(*) AS kms,
                   COALESCE(SUM(k.total_value),0) AS isk
            FROM killmails k
            JOIN ref_solar_systems ss ON ss.id = k.solar_system_id
            $where
            GROUP BY ss.id, ss.name, ss.security_status
            ORDER BY kms DESC
            LIMIT 10
        ", [$start]);

        $hourOfDay = DB::select("
            SELECT HOUR(k.killed_at) AS hr, COUNT(*) AS kms
            FROM killmails k
            $where
            GROUP BY HOUR(k.killed_at)
            ORDER BY hr ASC
        ", [$start]);

        return [
            'daily' => $daily,
            'ship_groups' => $shipGroups,
            'alliances' => $alliances,
            'systems' => $systems,
            'hour_of_day' => $hourOfDay,
        ];
    }

    /**
     * Compact recent-losses strip — last $limit war-attributable kills
     * for the side. Replaces the old 5,000-row daily list.
     *
     * @param  list<int>  $victimAlliances
     * @param  list<int>  $hostileAlliances
     * @return list<object>
     */
    private function recentLosses(array $victimAlliances, array $hostileAlliances, int $limit): array
    {
        if ($victimAlliances === [] || $hostileAlliances === []) {
            return [];
        }
        return DB::select("
            SELECT
                k.killmail_id, k.killed_at, k.total_value, k.victim_ship_type_id,
                k.victim_ship_type_name,
                ss.name AS system_name,
                vname.name AS victim_name,
                aname.name AS victim_alliance_name,
                fb_n.name AS fb_char_name,
                fb_an.name AS fb_alliance_name
            FROM killmails k
            JOIN ref_solar_systems ss ON ss.id = k.solar_system_id
            LEFT JOIN esi_entity_names vname  ON vname.entity_id = k.victim_character_id AND vname.category = 'character'
            LEFT JOIN esi_entity_names aname  ON aname.entity_id = k.victim_alliance_id AND aname.category = 'alliance'
            LEFT JOIN killmail_attackers fb   ON fb.killmail_id = k.killmail_id AND fb.is_final_blow = 1
            LEFT JOIN esi_entity_names fb_n   ON fb_n.entity_id = fb.character_id AND fb_n.category = 'character'
            LEFT JOIN esi_entity_names fb_an  ON fb_an.entity_id = fb.alliance_id AND fb_an.category = 'alliance'
            WHERE k.victim_alliance_id IN (" . implode(',', $victimAlliances) . ")
              AND EXISTS (
                  SELECT 1 FROM killmail_attackers a
                  WHERE a.killmail_id = k.killmail_id
                    AND a.alliance_id IN (" . implode(',', $hostileAlliances) . ")
              )
            ORDER BY k.killed_at DESC
            LIMIT $limit
        ");
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

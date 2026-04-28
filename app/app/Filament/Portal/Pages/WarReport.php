<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

    protected static ?string $title = 'War Report — WinterCo vs Imperium + Initiative';

    protected static ?string $slug = 'war-report';

    protected string $view = 'filament.portal.pages.war-report';

    /** Conflict floor — every query is bounded by this. */
    private const string WAR_START = '2026-04-02 00:00:00';

    /** Bloc ids (coalition_blocs.id). Side membership is bloc-wide so
     *  every alliance flying with the bloc shows up in victim tables /
     *  pilot kills, not just the named lead alliance. */
    private const int WINTERCO_BLOC_ID = 1;
    private const int IMPERIUM_BLOC_ID = 3;

    /** The Initiative. anchor — bloc 7 has only the lead alliance,
     *  so we infer the rest of the bloc dynamically from the rolling
     *  alliance-pair behaviour table (aligned + loosely-coordinated
     *  to this anchor). See inferInitiativeAlly(). */
    private const int INITIATIVE_ANCHOR_ALLIANCE_ID = 1900696668;

    /** Cache TTL — buildViewData scans 26+ days of killmails +
     *  killmail_attackers and takes ~90s uncached, which trips nginx's
     *  60s timeout. We rely on a 4-minute scheduled warmer to keep the
     *  cache populated; the 10-minute TTL is double the warm interval
     *  so a single missed warm cycle still serves last-known-good.
     *  Visitors never wait on a cold cache. */
    public const int VIEW_CACHE_TTL_SECONDS = 600;
    /** Cache key — bump the v-suffix whenever the view-data shape
     *  changes (new top-level keys, removed keys, changed sub-array
     *  structure). Otherwise stale cached payloads from before the
     *  edit will trip "incomplete object" 500s in the blade once
     *  the new compiled view tries to read keys that don't exist.
     *  Bump → operator runs `php artisan cache:clear` once. */
    public const string VIEW_CACHE_KEY = 'war_report.view_data.v6';

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        // TTL alone controls freshness; the 4-minute war-report-warm
        // scheduled task (routes/console.php) refreshes via
        // Cache::put + buildViewData() before the TTL expires. If the
        // warmer ever misses a cycle and the cache is empty, we
        // fall back to building inline — slow but correct.
        return Cache::remember(
            self::VIEW_CACHE_KEY,
            self::VIEW_CACHE_TTL_SECONDS,
            fn (): array => $this->buildViewData(),
        );
    }

    /**
     * Public so the war-report-warm scheduled task can drive the
     * rebuild without going through Cache::remember (which would
     * short-circuit on a still-warm value and skip the refresh).
     *
     * @return array<string, mixed>
     */
    public function buildViewData(): array
    {
        $start = self::WAR_START;
        $now = now();
        $totalDays = max(1, (int) Carbon::parse($start)->diffInDays($now));

        // Bloc-wide alliance lists. Side membership is broad — every
        // partner alliance flying with the bloc registers as victim /
        // attacker, not just the named lead alliance.
        $wcAlly = $this->blocAlliances(self::WINTERCO_BLOC_ID);
        $imperiumAlly = $this->blocAlliances(self::IMPERIUM_BLOC_ID);
        $initiativeAlly = $this->inferInitiativeAlly();
        $hostile = array_values(array_unique(array_merge($imperiumAlly, $initiativeAlly)));

        // Materialise the war-attributable killmail set into a per-
        // connection temp table once, then JOIN against it for every
        // downstream query. Without this each query repeats the same
        // ~12s EXISTS scan over 1.6M+ killmail_attackers rows; uncached
        // build dropped from ~92s to single-digit seconds in profiling.
        $this->materialiseWarKillSet($start, $wcAlly, $hostile);

        // Side-level totals + ISK across the entire conflict (not just
        // the rendered window) — used in the hero banner and tile row.
        $totals = [
            'wc'   => $this->sideLossTotals($start, $wcAlly, $hostile),
            'goon' => $this->sideLossTotals($start, $imperiumAlly, $wcAlly),
            'init' => $this->sideLossTotals($start, $initiativeAlly, $wcAlly),
        ];

        // Aggregated rollups per side — daily activity, ship-group
        // breakdown, top alliances, top systems. These power the
        // histograms / horizontal bar charts on the page; raw
        // killmail lists are limited to a small "recent" strip.
        $rollups = [
            'wc'   => $this->sideRollups($start, $wcAlly, $hostile),
            'goon' => $this->sideRollups($start, $imperiumAlly, $wcAlly),
            'init' => $this->sideRollups($start, $initiativeAlly, $wcAlly),
        ];

        // Compact "recent" feed per side — last 15 mails so the
        // operator can eyeball the latest activity without scrolling
        // a 5k-row list.
        $recent = [
            'wc'   => $this->recentLosses($wcAlly, $hostile, 15),
            'goon' => $this->recentLosses($imperiumAlly, $wcAlly, 15),
            'init' => $this->recentLosses($initiativeAlly, $wcAlly, 15),
        ];

        $hotspots = $this->systemHotspots($start, $wcAlly, $hostile);
        $structures = $this->upwellStructureTimeline($start, $wcAlly, $hostile);
        $topImplantPods = $this->topImplantPods($start, $wcAlly, $hostile);
        $leaderboards = $this->leaderboards($start, $wcAlly, $hostile);

        return [
            'war_start' => $start,
            'total_days' => $totalDays,
            'totals' => $totals,
            'rollups' => $rollups,
            'recent' => $recent,
            'hotspots' => $hotspots,
            'structures' => $structures,
            'top_implant_pods' => $topImplantPods,
            'leaderboards' => $leaderboards,
            'wc_alliance_count' => count($wcAlly),
        ];
    }

    /**
     * Active alliance ids for a coalition bloc.
     *
     * @return list<int>
     */
    private function blocAlliances(int $blocId): array
    {
        $rows = DB::table('coalition_entity_labels')
            ->where('bloc_id', $blocId)
            ->where('entity_type', 'alliance')
            ->where('is_active', 1)
            ->pluck('entity_id')->all();
        return array_values(array_map('intval', $rows));
    }

    /**
     * Inferred Initiative bloc — anchor alliance plus every alliance
     * the rolling alliance-pair-behaviour signal labels as `aligned`
     * or `loosely coordinated` (90d co-fight window). The dedicated
     * Initiative coalition_bloc only has the anchor row, so this
     * pulls the actual partner alliances from the rolling signal
     * computed by python/bloc_intel/extractor.py.
     *
     * @return list<int>
     */
    private function inferInitiativeAlly(): array
    {
        $anchor = self::INITIATIVE_ANCHOR_ALLIANCE_ID;

        // Candidates = every alliance with at least one rolling-pair
        // row against the anchor. Fast: small ordered scan on the
        // pair-behaviour index, no killmail join.
        $rows = DB::table('alliance_pair_behavior_rolling')
            ->where(function ($q) use ($anchor): void {
                $q->where('alliance_a_id', $anchor)
                  ->orWhere('alliance_b_id', $anchor);
            })
            ->orderByDesc('window_end_date')
            ->get(['alliance_a_id', 'alliance_b_id', 'affinity_score', 'hostility_score', 'confidence', 'n_obs', 'window_end_date']);

        // Keep the newest row per counterpart (rolling table can have
        // multiple windows; we always want the freshest one).
        $latest = [];
        foreach ($rows as $row) {
            $cid = (int) ($row->alliance_a_id === $anchor ? $row->alliance_b_id : $row->alliance_a_id);
            if (! isset($latest[$cid])) {
                $latest[$cid] = $row;
            }
        }

        $svc = app(\App\Domains\BlocIntel\Services\BlocRelationshipService::class);
        $allies = [$anchor];
        foreach ($latest as $cid => $row) {
            $label = $svc->deriveLabel(
                (float) $row->affinity_score,
                (float) $row->hostility_score,
                (float) $row->confidence,
                (int) $row->n_obs,
            );
            if ($label === 'aligned' || $label === 'loosely coordinated') {
                $allies[] = $cid;
            }
        }
        return array_values(array_unique($allies));
    }

    /**
     * Conflict-wide leaderboards. Each list is computed once in SQL,
     * groups: most-valuable-single-kill, top pilot/alliance kills,
     * top pilot/alliance losses (count + ISK).
     *
     * Kills = killmails the pilot/alliance was on the attacker side
     * for, where the victim was on the opposing side.
     * Losses = killmails where the pilot/alliance was the victim AND
     * at least one attacker was on the opposing side.
     *
     * @param  list<int>  $wcAlly
     * @param  list<int>  $hostile
     * @return array<string, list<object>>
     */
    private function leaderboards(string $start, array $wcAlly, array $hostile): array
    {
        if ($wcAlly === [] || $hostile === []) {
            return [];
        }
        $wcStr = implode(',', $wcAlly);
        $hStr = implode(',', $hostile);

        // Top 10 most valuable single kills — any war-attributable km.
        $mostValuable = DB::select("
            SELECT k.killmail_id, k.killed_at, k.total_value,
                   k.victim_ship_type_name, k.victim_character_id, k.victim_alliance_id,
                   ss.name AS system_name,
                   en.name AS victim_name, an.name AS victim_alliance_name,
                   CASE
                       WHEN k.victim_alliance_id IN ($wcStr) THEN 'wc'
                       WHEN k.victim_alliance_id IN ($hStr) THEN 'hostile'
                       ELSE 'other'
                   END AS side
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            JOIN ref_solar_systems ss ON ss.id = k.solar_system_id
            LEFT JOIN esi_entity_names en ON en.entity_id = k.victim_character_id AND en.category = 'character'
            LEFT JOIN esi_entity_names an ON an.entity_id = k.victim_alliance_id AND an.category = 'alliance'
            ORDER BY k.total_value DESC
            LIMIT 10
        ");

        // Top pilots by attacker involvements (war-attributable kills).
        // COUNT(DISTINCT killmail_id) so a pilot appearing on multiple
        // killmails counts each once. ISK is awarded by FB only to
        // avoid blue-on-blue inflation.
        $topPilotsKills = DB::select("
            SELECT a.character_id AS id,
                   COALESCE(en.name, CONCAT('#', a.character_id)) AS name,
                   COALESCE(an.name, '?') AS alliance_name,
                   COUNT(DISTINCT a.killmail_id) AS kills,
                   SUM(CASE WHEN a.is_final_blow = 1 THEN k.total_value ELSE 0 END) AS isk_fb
            FROM _war_attackers a
            JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
            JOIN killmails k ON k.killmail_id = a.killmail_id
            LEFT JOIN esi_entity_names en ON en.entity_id = a.character_id AND en.category = 'character'
            LEFT JOIN esi_entity_names an ON an.entity_id = a.alliance_id AND an.category = 'alliance'
            WHERE a.character_id IS NOT NULL AND a.character_id > 0
              AND a.attacker_side <> wk.victim_side
            GROUP BY a.character_id, en.name, an.name
            ORDER BY kills DESC
            LIMIT 10
        ");

        // Top pilots by losses (count + isk).
        $topPilotsLosses = DB::select("
            SELECT k.victim_character_id AS id,
                   COALESCE(en.name, CONCAT('#', k.victim_character_id)) AS name,
                   COALESCE(an.name, '?') AS alliance_name,
                   COUNT(*) AS losses,
                   SUM(k.total_value) AS isk_lost
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            LEFT JOIN esi_entity_names en ON en.entity_id = k.victim_character_id AND en.category = 'character'
            LEFT JOIN esi_entity_names an ON an.entity_id = k.victim_alliance_id AND an.category = 'alliance'
            WHERE k.victim_character_id IS NOT NULL AND k.victim_character_id > 0
            GROUP BY k.victim_character_id, en.name, an.name
            ORDER BY losses DESC
            LIMIT 10
        ");

        // Top alliances by total kills (any member on attacker list of
        // a war-attributable km).
        $topAllianceKills = DB::select("
            SELECT a.alliance_id AS id,
                   COALESCE(an.name, CONCAT('#', a.alliance_id)) AS name,
                   COUNT(DISTINCT a.killmail_id) AS kills
            FROM _war_attackers a
            JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
            LEFT JOIN esi_entity_names an ON an.entity_id = a.alliance_id AND an.category = 'alliance'
            WHERE a.alliance_id IS NOT NULL AND a.alliance_id > 0
              AND a.attacker_side <> wk.victim_side
            GROUP BY a.alliance_id, an.name
            ORDER BY kills DESC
            LIMIT 10
        ");

        // Top alliances by total losses (already partially in
        // sideRollups, but here without the per-side filter so a
        // single combined leaderboard lists everyone).
        $topAllianceLosses = DB::select("
            SELECT k.victim_alliance_id AS id,
                   COALESCE(an.name, CONCAT('#', k.victim_alliance_id)) AS name,
                   COUNT(*) AS losses,
                   SUM(k.total_value) AS isk_lost
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            LEFT JOIN esi_entity_names an ON an.entity_id = k.victim_alliance_id AND an.category = 'alliance'
            WHERE k.victim_alliance_id IS NOT NULL
            GROUP BY k.victim_alliance_id, an.name
            ORDER BY isk_lost DESC
            LIMIT 10
        ");

        return [
            'most_valuable' => $mostValuable,
            'top_pilots_kills' => $topPilotsKills,
            'top_pilots_losses' => $topPilotsLosses,
            'top_alliance_kills' => $topAllianceKills,
            'top_alliance_losses' => $topAllianceLosses,
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
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            JOIN ref_solar_systems ss ON ss.id = k.solar_system_id
            LEFT JOIN esi_entity_names en ON en.entity_id = k.victim_character_id AND en.category = 'character'
            LEFT JOIN esi_entity_names an ON an.entity_id = k.victim_alliance_id AND an.category = 'alliance'
            WHERE k.victim_ship_type_id IN (670, 33328)
              AND k.total_value > 0
            ORDER BY k.total_value DESC
            LIMIT 10
        ");
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
        // Reads from the materialised _war_kms temp table built once
        // per request — see materialiseWarKillSet(). Filters by victim
        // side and the (separate) opposing-attacker set so we can
        // reuse a single shared killmail set without rebuilding it
        // for each (victim, hostile) pair.
        $row = DB::selectOne("
            SELECT COUNT(*) AS n,
                   COALESCE(SUM(k.total_value), 0) AS isk
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            WHERE k.victim_alliance_id IN (" . implode(',', $victimAlliances) . ")
              AND EXISTS (
                  SELECT 1 FROM killmail_attackers a
                  WHERE a.killmail_id = k.killmail_id
                    AND a.alliance_id IN (" . implode(',', $hostileAlliances) . ")
              )
        ");
        return ['kms' => (int) $row->n, 'isk' => (float) $row->isk];
    }

    /**
     * One-shot temp-table population. Holds every war-attributable
     * killmail_id. Connection-scoped — automatically dropped at end
     * of the request.
     *
     * @param  list<int>  $wcAlly
     * @param  list<int>  $hostile
     */
    private function materialiseWarKillSet(string $start, array $wcAlly, array $hostile): void
    {
        $wcStr = implode(',', $wcAlly);
        $hStr = implode(',', $hostile);

        DB::statement("DROP TEMPORARY TABLE IF EXISTS _war_kms");
        DB::statement("DROP TEMPORARY TABLE IF EXISTS _war_attackers");

        // ENGINE=MEMORY for the lookup speed; MariaDB falls back to
        // MyISAM on overflow but with ~85k rows we stay well within
        // tmp_table_size. PRIMARY KEY ensures O(1) JOIN lookups.
        DB::statement("
            CREATE TEMPORARY TABLE _war_kms (
                killmail_id BIGINT UNSIGNED NOT NULL,
                victim_side ENUM('wc','hostile') NOT NULL,
                PRIMARY KEY (killmail_id),
                KEY (victim_side)
            ) ENGINE=MEMORY
        ");
        DB::statement("
            INSERT INTO _war_kms (killmail_id, victim_side)
            SELECT k.killmail_id,
                   CASE WHEN k.victim_alliance_id IN ($wcStr) THEN 'wc' ELSE 'hostile' END
            FROM killmails k
            WHERE k.killed_at >= ?
              AND (
                  (k.victim_alliance_id IN ($wcStr) AND EXISTS(SELECT 1 FROM killmail_attackers a WHERE a.killmail_id = k.killmail_id AND a.alliance_id IN ($hStr)))
                  OR
                  (k.victim_alliance_id IN ($hStr) AND EXISTS(SELECT 1 FROM killmail_attackers a WHERE a.killmail_id = k.killmail_id AND a.alliance_id IN ($wcStr)))
              )
        ", [$start]);

        // Side-tagged attacker rows for the war's killmails. Pilot
        // leaderboards group by character_id, so we materialise the
        // (killmail × attacker) projection once instead of re-joining
        // killmail_attackers in every leaderboard query.
        DB::statement("
            CREATE TEMPORARY TABLE _war_attackers (
                killmail_id BIGINT UNSIGNED NOT NULL,
                character_id BIGINT UNSIGNED NULL,
                alliance_id BIGINT UNSIGNED NULL,
                is_final_blow TINYINT(1) NOT NULL DEFAULT 0,
                attacker_side ENUM('wc','hostile') NOT NULL,
                KEY (character_id),
                KEY (alliance_id),
                KEY (killmail_id, character_id)
            ) ENGINE=MEMORY
        ");
        DB::statement("
            INSERT INTO _war_attackers (killmail_id, character_id, alliance_id, is_final_blow, attacker_side)
            SELECT a.killmail_id, a.character_id, a.alliance_id, a.is_final_blow,
                   CASE WHEN a.alliance_id IN ($wcStr) THEN 'wc' ELSE 'hostile' END
            FROM _war_kms wk
            JOIN killmail_attackers a ON a.killmail_id = wk.killmail_id
            WHERE a.alliance_id IN ($wcStr) OR a.alliance_id IN ($hStr)
        ");
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
        // hostileAlliances unused now — _war_kms already encodes the
        // war-attributable filter for both directions.
        unset($hostileAlliances);
        $where = "
            WHERE k.victim_alliance_id IN ($vStr)
        ";

        $daily = DB::select("
            SELECT DATE(k.killed_at) AS day, COUNT(*) AS kms, COALESCE(SUM(k.total_value),0) AS isk
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            $where
            GROUP BY DATE(k.killed_at)
            ORDER BY day ASC
        ");

        $shipGroups = DB::select("
            SELECT COALESCE(NULLIF(k.victim_ship_group_name,''), 'Unknown') AS label,
                   COUNT(*) AS kms,
                   COALESCE(SUM(k.total_value),0) AS isk
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            $where
            GROUP BY label
            ORDER BY kms DESC
            LIMIT 12
        ");

        $alliances = DB::select("
            SELECT k.victim_alliance_id AS id,
                   COALESCE(en.name, CONCAT('#', k.victim_alliance_id)) AS label,
                   COUNT(*) AS kms,
                   COALESCE(SUM(k.total_value),0) AS isk
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            LEFT JOIN esi_entity_names en ON en.entity_id = k.victim_alliance_id AND en.category = 'alliance'
            $where
            GROUP BY k.victim_alliance_id, en.name
            ORDER BY kms DESC
            LIMIT 10
        ");

        $systems = DB::select("
            SELECT ss.id, ss.name AS label, ss.security_status,
                   COUNT(*) AS kms,
                   COALESCE(SUM(k.total_value),0) AS isk
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            JOIN ref_solar_systems ss ON ss.id = k.solar_system_id
            $where
            GROUP BY ss.id, ss.name, ss.security_status
            ORDER BY kms DESC
            LIMIT 10
        ");

        $hourOfDay = DB::select("
            SELECT HOUR(k.killed_at) AS hr, COUNT(*) AS kms
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            $where
            GROUP BY HOUR(k.killed_at)
            ORDER BY hr ASC
        ");

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
        unset($hostileAlliances);
        // enriched_at IS NOT NULL filters out the last few minutes of
        // in-flight ingest where ship name + ISK haven't resolved yet
        // (otherwise the recent-feed rows render as "— · 0" placeholders
        // and look broken). 4× the requested limit on the inner SELECT
        // gives us headroom to skip unenriched rows; outer LIMIT clamps
        // to the requested count.
        $innerLimit = $limit * 4;
        return DB::select("
            SELECT
                k.killmail_id, k.killed_at, k.total_value, k.victim_ship_type_id,
                k.victim_ship_type_name,
                ss.name AS system_name,
                vname.name AS victim_name,
                aname.name AS victim_alliance_name,
                fb_n.name AS fb_char_name,
                fb_an.name AS fb_alliance_name
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            JOIN ref_solar_systems ss ON ss.id = k.solar_system_id
            LEFT JOIN esi_entity_names vname  ON vname.entity_id = k.victim_character_id AND vname.category = 'character'
            LEFT JOIN esi_entity_names aname  ON aname.entity_id = k.victim_alliance_id AND aname.category = 'alliance'
            LEFT JOIN killmail_attackers fb   ON fb.killmail_id = k.killmail_id AND fb.is_final_blow = 1
            LEFT JOIN esi_entity_names fb_n   ON fb_n.entity_id = fb.character_id AND fb_n.category = 'character'
            LEFT JOIN esi_entity_names fb_an  ON fb_an.entity_id = fb.alliance_id AND fb_an.category = 'alliance'
            WHERE k.victim_alliance_id IN (" . implode(',', $victimAlliances) . ")
              AND k.enriched_at IS NOT NULL
              AND k.victim_ship_type_name IS NOT NULL
              AND k.victim_ship_type_name <> ''
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
        return DB::select("
            SELECT k.solar_system_id, ss.name AS system_name, ss.security_status,
                   COUNT(*) AS km_count,
                   COALESCE(SUM(k.total_value), 0) AS isk_destroyed,
                   MAX(k.killed_at) AS last_km
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            JOIN ref_solar_systems ss ON ss.id = k.solar_system_id
            GROUP BY k.solar_system_id, ss.name, ss.security_status
            ORDER BY km_count DESC
            LIMIT 20
        ");
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
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            JOIN ref_solar_systems ss ON ss.id = k.solar_system_id
            LEFT JOIN esi_entity_names vc ON vc.entity_id = k.victim_corporation_id AND vc.category = 'corporation'
            LEFT JOIN esi_entity_names va ON va.entity_id = k.victim_alliance_id AND va.category = 'alliance'
            WHERE k.victim_ship_category_id = 65
            ORDER BY k.killed_at DESC
        ");
    }
}

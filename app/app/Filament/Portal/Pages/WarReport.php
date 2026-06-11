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
 * /portal/war-report/{conflict} — scoped war report.
 *
 * Conflict slug picks the opposing bloc:
 *   vs-imperium   → WinterCo bloc (1) vs Imperium bloc (3)
 *   vs-initiative → WinterCo bloc (1) vs The Initiative.
 *                   (anchor + inferred-aligned partner alliances)
 *
 * Pure descriptive surface: every war-attributable killmail (victim
 * on one side, ≥1 attacker on the opposing side), 2-column per-side
 * histograms, system hotspots, leaderboards, structure timeline.
 */
class WarReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-fire';

    protected static ?string $navigationLabel = 'War Report';

    protected static string|UnitEnum|null $navigationGroup = 'Strategic';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'war-report/{conflict}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.portal.pages.war-report';

    public string $conflict = self::CONFLICT_IMPERIUM;

    public const string CONFLICT_IMPERIUM = 'vs-imperium';
    public const string CONFLICT_INITIATIVE = 'vs-initiative';

    public const array CONFLICTS = [
        self::CONFLICT_IMPERIUM => [
            'opposing_label' => 'Imperium',
            'opposing_tint' => '#c474a8',
            'start' => '2026-04-02 00:00:00',
        ],
        self::CONFLICT_INITIATIVE => [
            // Winter Coalition / Fraternity opened the offensive
            // against The Initiative. in the first half of October
            // 2025 — anchor the floor at 2025-10-01 so this report
            // covers the full conflict, not just post-April action.
            'opposing_label' => 'Initiative',
            'opposing_tint' => '#d49862',
            'start' => '2025-10-01 00:00:00',
        ],
    ];

    public function mount(string $conflict): void
    {
        $this->conflict = isset(self::CONFLICTS[$conflict]) ? $conflict : self::CONFLICT_IMPERIUM;
    }

    public function getTitle(): string
    {
        return 'War Report — ' . self::displayLabel($this->conflict);
    }

    /**
     * Display label for a conflict — alternates side order per render
     * so the page reads "WinterCo vs Imperium" half the time and
     * "Imperium vs WinterCo" the other half. No persistent ordering
     * → both sides appear first equally often. Random per request,
     * not cached at the data layer (label is computed in the blade).
     */
    public static function displayLabel(string $conflict): string
    {
        $opposing = self::CONFLICTS[$conflict]['opposing_label'] ?? 'Unknown';
        return mt_rand(0, 1) === 0
            ? "WinterCo vs {$opposing}"
            : "{$opposing} vs WinterCo";
    }

    /** Default conflict floor — overridable per-conflict via CONFLICTS. */
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
    public const string VIEW_CACHE_KEY = 'war_report.view_data.v19';

    /** Per-metric rank-1/2/3 podium titles. Reddit-flavored,
     *  curse-word-free, distinct per leaderboard. */
    public const array PODIUM_TITLES = [
        'kills' => [
            1 => '🥇 Tip of the Spear',
            2 => '🥈 On Every Mailing List',
            3 => '🥉 Persistent Threat',
        ],
        'final_blows' => [
            1 => '🥇 Last-Word Specialist',
            2 => '🥈 Trigger Discipline +',
            3 => '🥉 Cleanup Crew Captain',
        ],
        'isk_destroyed' => [
            1 => '🥇 Wallet Apocalypse',
            2 => '🥈 Inflation Fixer',
            3 => '🥉 Mom\'s Wallet Was On Grid',
        ],
        'battles_attended' => [
            1 => '🥇 Ping Always Answered',
            2 => '🥈 Iron Lungs On Comms',
            3 => '🥉 Will X-Up For Snacks',
        ],
        'small_gang_kills' => [
            1 => '🥇 Tuskers-Tier',
            2 => '🥈 Low-Volume, High-Yield',
            3 => '🥉 Always Solo, Never Alone',
        ],
        'most_feared' => [
            1 => '🥇 Primary Target Caller\'s Nightmare',
            2 => '🥈 Remove Him From Grid',
            3 => '🥉 Broadcasted For Armor',
        ],
        'hardest_to_kill' => [
            1 => '🥇 Slipperier Than A Cyno Venture',
            2 => '🥈 Warp Core Enjoyer',
            3 => '🥉 Docking Request Approved',
        ],
        'biggest_menace' => [
            1 => '🥇 Can Somebody Deal With Him',
            2 => '🥈 Permanent Red In Local',
            3 => '🥉 Somehow Already Here',
        ],
    ];
    public const string THEATER_IDS_CACHE_KEY = 'war_report.theater_ids.v1';

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        // Cache key per conflict — 2-side payloads differ between
        // vs-imperium and vs-initiative, so each gets its own slot.
        $key = self::VIEW_CACHE_KEY . '.' . $this->conflict;
        return Cache::remember(
            $key,
            self::VIEW_CACHE_TTL_SECONDS,
            fn (): array => $this->buildViewData($this->conflict),
        ) + [
            'conflict_key' => $this->conflict,
            // Display label is intentionally OUT of the cached payload
            // so each request gets a fresh swap (WinterCo vs X / X vs
            // WinterCo). Cached label would lock the order for 10 min.
            'display_label' => self::displayLabel($this->conflict),
        ];
    }

    /**
     * Public so the war-report-warm scheduled task can drive the
     * rebuild without going through Cache::remember (which would
     * short-circuit on a still-warm value and skip the refresh).
     *
     * @return array<string, mixed>
     */
    public function buildViewData(string $conflict = self::CONFLICT_IMPERIUM): array
    {
        if (! isset(self::CONFLICTS[$conflict])) {
            $conflict = self::CONFLICT_IMPERIUM;
        }
        $start = self::CONFLICTS[$conflict]['start'] ?? self::WAR_START;
        $now = now();
        $totalDays = max(1, (int) Carbon::parse($start)->diffInDays($now));

        $wcAlly = $this->blocAlliances(self::WINTERCO_BLOC_ID);
        $opposingAlly = $conflict === self::CONFLICT_INITIATIVE
            ? $this->inferInitiativeAlly()
            : $this->blocAlliances(self::IMPERIUM_BLOC_ID);

        // Materialise the war-attributable killmail set for this
        // conflict pair into a per-connection temp table; downstream
        // queries JOIN against it instead of re-running EXISTS scans.
        $this->materialiseWarKillSet($start, $wcAlly, $opposingAlly);

        $totals = [
            'wc' => $this->sideLossTotals($start, $wcAlly, $opposingAlly),
            'op' => $this->sideLossTotals($start, $opposingAlly, $wcAlly),
        ];
        $rollups = [
            'wc' => $this->sideRollups($start, $wcAlly, $opposingAlly),
            'op' => $this->sideRollups($start, $opposingAlly, $wcAlly),
        ];
        $recent = [
            'wc' => $this->recentLosses($wcAlly, $opposingAlly, 15),
            'op' => $this->recentLosses($opposingAlly, $wcAlly, 15),
        ];

        // Align ship_groups across both sides so the rendered rows
        // match: if WC lost a Titan, the opposing column has a Titan
        // row at the same position with kms=0/isk=0. Operator can
        // visually compare side-by-side without scanning for labels.
        $rollups = $this->alignShipGroups($rollups);

        $hotspots = $this->systemHotspots($start, $wcAlly, $opposingAlly);
        $structures = $this->upwellStructureTimeline($start, $wcAlly, $opposingAlly);
        $structureWar = $this->structureWarTotals($wcAlly, $opposingAlly);
        $systemsWar = $this->systemsWarTotals($wcAlly, $opposingAlly);
        $sovWar = $this->sovWarTotals($wcAlly, $opposingAlly, $start);
        $topImplantPods = $this->topImplantPods($start, $wcAlly, $opposingAlly);
        $leaderboards = $this->leaderboards($start, $wcAlly, $opposingAlly);
        $liveBattles = $this->liveBattles($wcAlly, $opposingAlly);
        $tickerKills = $this->tickerKills(12, $wcAlly, $opposingAlly);
        $podiums = $this->badgePodiums();

        $opposingLabel = self::CONFLICTS[$conflict]['opposing_label'];
        $opposingTint = self::CONFLICTS[$conflict]['opposing_tint'];

        return [
            'war_start' => $start,
            'total_days' => $totalDays,
            'conflict' => $conflict,
            'opposing_label' => $opposingLabel,
            'opposing_tint' => $opposingTint,
            'totals' => $totals,
            'rollups' => $rollups,
            'recent' => $recent,
            'hotspots' => $hotspots,
            'structures' => $structures,
            'structure_war' => $structureWar,
            'systems_war' => $systemsWar,
            'sov_war' => $sovWar,
            'top_implant_pods' => $topImplantPods,
            'leaderboards' => $leaderboards,
            'live_battles' => $liveBattles,
            'ticker_kills' => $tickerKills,
            'podiums' => $podiums,
        ];
    }

    /**
     * Top-3 character podium per badge metric (kills / final blows /
     * isk destroyed / battles attended / small-gang kills). Joined
     * to esi_entity_names for portrait + alliance label. Reads from
     * the materialised _war_attackers + _war_kms tables that
     * buildViewData has already populated.
     *
     * @return array<string, list<object>>
     */
    private function badgePodiums(): array
    {
        $base = "
            FROM _war_attackers a
            JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
            JOIN killmails k ON k.killmail_id = a.killmail_id
            LEFT JOIN esi_entity_names en ON en.entity_id = a.character_id AND en.category = 'character'
            LEFT JOIN esi_entity_names an ON an.entity_id = a.alliance_id AND an.category = 'alliance'
            LEFT JOIN battle_theater_killmails btk ON btk.killmail_id = a.killmail_id
            WHERE a.character_id IS NOT NULL AND a.character_id > 0
              AND a.attacker_side <> wk.victim_side
            GROUP BY a.character_id, a.alliance_id, en.name, an.name
        ";
        $select = "SELECT a.character_id AS id, a.alliance_id, en.name AS name, an.name AS alliance_name";

        $kills = DB::select("$select, COUNT(DISTINCT a.killmail_id) AS metric $base ORDER BY metric DESC LIMIT 3");
        $finalBlows = DB::select("$select, SUM(CASE WHEN a.is_final_blow = 1 THEN 1 ELSE 0 END) AS metric $base ORDER BY metric DESC LIMIT 3");
        $iskDestroyed = DB::select("$select, SUM(CASE WHEN a.is_final_blow = 1 THEN k.total_value ELSE 0 END) AS metric $base ORDER BY metric DESC LIMIT 3");
        $battles = DB::select("$select, COUNT(DISTINCT btk.theater_id) AS metric $base ORDER BY metric DESC LIMIT 3");
        $smallGang = DB::select("$select, COUNT(DISTINCT CASE WHEN k.attacker_count <= 5 THEN a.killmail_id END) AS metric $base ORDER BY metric DESC LIMIT 3");
        $mostFeared = DB::select("$select, SUM(CASE WHEN k.total_value >= 1000000000 THEN k.total_value ELSE 0 END) AS metric $base HAVING metric > 0 ORDER BY metric DESC LIMIT 3");
        $biggestMenace = DB::select("$select, COUNT(DISTINCT k.victim_character_id) AS metric $base ORDER BY metric DESC LIMIT 3");
        // Hardest-to-kill: kills/(kills+losses) with a min activity
        // gate so a one-mail wonder doesn't take the podium.
        $hardestToKill = DB::select("
            SELECT cid AS id,
                   MAX(name) AS name, MAX(alliance_id) AS alliance_id, MAX(alliance_name) AS alliance_name,
                   ROUND(SUM(kills) * 100.0 / NULLIF(SUM(kills) + SUM(losses), 0), 2) AS metric
            FROM (
                SELECT a.character_id AS cid,
                       en.name AS name, a.alliance_id AS alliance_id, an.name AS alliance_name,
                       COUNT(DISTINCT a.killmail_id) AS kills,
                       0 AS losses
                FROM _war_attackers a
                JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
                LEFT JOIN esi_entity_names en ON en.entity_id = a.character_id AND en.category = 'character'
                LEFT JOIN esi_entity_names an ON an.entity_id = a.alliance_id AND an.category = 'alliance'
                WHERE a.character_id IS NOT NULL AND a.character_id > 0
                  AND a.attacker_side <> wk.victim_side
                GROUP BY a.character_id, en.name, a.alliance_id, an.name
                UNION ALL
                SELECT k.victim_character_id AS cid,
                       en.name AS name, k.victim_alliance_id AS alliance_id, an.name AS alliance_name,
                       0, COUNT(*) AS losses
                FROM _war_kms wk
                JOIN killmails k ON k.killmail_id = wk.killmail_id
                LEFT JOIN esi_entity_names en ON en.entity_id = k.victim_character_id AND en.category = 'character'
                LEFT JOIN esi_entity_names an ON an.entity_id = k.victim_alliance_id AND an.category = 'alliance'
                WHERE k.victim_character_id IS NOT NULL AND k.victim_character_id > 0
                GROUP BY k.victim_character_id, en.name, k.victim_alliance_id, an.name
            ) AS combined
            GROUP BY cid
            HAVING SUM(kills) + SUM(losses) >= 30
            ORDER BY metric DESC LIMIT 3
        ");

        return [
            'kills' => $kills,
            'final_blows' => $finalBlows,
            'isk_destroyed' => $iskDestroyed,
            'battles_attended' => $battles,
            'small_gang_kills' => $smallGang,
            'most_feared' => $mostFeared,
            'hardest_to_kill' => $hardestToKill,
            'biggest_menace' => $biggestMenace,
        ];
    }

    /**
     * Battle theaters that are still live for the current conflict —
     * either explicitly open (end_time IS NULL) or with a killmail in
     * the last 90 minutes. Ordered newest-first; the blade ticker
     * pulls the top few for the running banner.
     *
     * @param  list<int>  $wcAlly
     * @param  list<int>  $opposingAlly
     * @return list<object>
     */
    private function liveBattles(array $wcAlly, array $opposingAlly): array
    {
        if ($wcAlly === [] || $opposingAlly === []) {
            return [];
        }
        $wcStr = implode(',', $wcAlly);
        $opStr = implode(',', $opposingAlly);
        // Live filter:
        //   - newest war-attributable km in the last 90 min
        //   - end_time NULL or also <90 min stale
        //   - ≥ 5 distinct pilots on EACH conflict bloc in the
        //     theater participants table — keeps unrelated highsec
        //     skirmishes (e.g. one Goons pilot in an Osmon Safety. fight)
        //     from leaking onto the live banner.
        return DB::select("
            SELECT
                t.id,
                t.public_slug,
                t.primary_system_id,
                ss.name AS system_name,
                ss.security_status,
                t.start_time,
                t.end_time,
                t.total_kills,
                t.total_isk_lost,
                live.newest_km
            FROM (
                SELECT btk.theater_id AS id, MAX(k.killed_at) AS newest_km
                FROM _war_kms wk
                JOIN battle_theater_killmails btk ON btk.killmail_id = wk.killmail_id
                JOIN killmails k ON k.killmail_id = btk.killmail_id
                GROUP BY btk.theater_id
                HAVING newest_km > DATE_SUB(NOW(), INTERVAL 90 MINUTE)
            ) AS live
            JOIN battle_theaters t ON t.id = live.id
            JOIN ref_solar_systems ss ON ss.id = t.primary_system_id
            JOIN (
                SELECT theater_id,
                       COUNT(DISTINCT CASE WHEN alliance_id IN ($wcStr) THEN character_id END) AS wc_pilots,
                       COUNT(DISTINCT CASE WHEN alliance_id IN ($opStr) THEN character_id END) AS op_pilots
                FROM battle_theater_participants
                GROUP BY theater_id
                HAVING wc_pilots >= 5 AND op_pilots >= 5
            ) sides ON sides.theater_id = t.id
            WHERE t.end_time IS NULL OR t.end_time > DATE_SUB(NOW(), INTERVAL 90 MINUTE)
            ORDER BY live.newest_km DESC
            LIMIT 6
        ");
    }

    /**
     * Last 24h of war-attributable kills, top by ISK, for the running
     * banner. Trimmed payload — only what the ticker needs to render.
     *
     * Stricter filter than _war_kms: requires the FINAL-BLOW attacker
     * to be on the opposing bloc (vs WC victim) or on WC (vs opposing
     * victim). The base _war_kms set marks any km where the opposing
     * bloc had at least one attacker present, which leaks kms where
     * the actual fight was vs a different bloc and an opposing-bloc
     * pilot just tagged along. FB-strict tightens this to "kill was
     * meaningfully made by this conflict's pair".
     *
     * @param  list<int>  $wcAlly
     * @param  list<int>  $opposingAlly
     * @return list<object>
     */
    private function tickerKills(int $limit, array $wcAlly, array $opposingAlly): array
    {
        if ($wcAlly === [] || $opposingAlly === []) {
            return [];
        }
        $wcStr = implode(',', $wcAlly);
        $opStr = implode(',', $opposingAlly);
        return DB::select("
            SELECT k.killmail_id, k.killed_at, k.total_value,
                   k.victim_ship_type_id,
                   k.victim_ship_type_name,
                   k.victim_character_id,
                   k.victim_alliance_id,
                   ss.name AS system_name,
                   en.name AS victim_name,
                   an.name AS victim_alliance_name
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            JOIN ref_solar_systems ss ON ss.id = k.solar_system_id
            JOIN killmail_attackers fb ON fb.killmail_id = k.killmail_id AND fb.is_final_blow = 1
            LEFT JOIN esi_entity_names en ON en.entity_id = k.victim_character_id AND en.category = 'character'
            LEFT JOIN esi_entity_names an ON an.entity_id = k.victim_alliance_id AND an.category = 'alliance'
            WHERE k.killed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
              AND k.total_value > 0
              AND k.enriched_at IS NOT NULL
              AND (
                  (k.victim_alliance_id IN ($wcStr) AND fb.alliance_id IN ($opStr))
                  OR
                  (k.victim_alliance_id IN ($opStr) AND fb.alliance_id IN ($wcStr))
              )
            ORDER BY k.total_value DESC
            LIMIT ?
        ", [$limit]);
    }

    /**
     * Make sure the wc + op ship_groups arrays render the same rows
     * in the same order. Combined order: pinned classes by priority
     * (Titan → Super → other caps), then everything else by combined
     * count desc. Missing rows on either side are zero-filled so the
     * blade renders one row per group on both sides.
     *
     * @param  array<string, array<string, mixed>>  $rollups
     * @return array<string, array<string, mixed>>
     */
    private function alignShipGroups(array $rollups): array
    {
        $wc = $rollups['wc']['ship_groups'] ?? [];
        $op = $rollups['op']['ship_groups'] ?? [];
        if ($wc === [] && $op === []) {
            return $rollups;
        }

        $byLabel = [];
        $captureRow = function (string $side, array $rows) use (&$byLabel): void {
            foreach ($rows as $row) {
                $label = (string) $row->label;
                if (! isset($byLabel[$label])) {
                    $byLabel[$label] = [
                        'priority' => (int) $row->priority,
                        'wc' => null,
                        'op' => null,
                        'combined' => 0,
                    ];
                }
                $byLabel[$label][$side] = $row;
                $byLabel[$label]['combined'] += (int) $row->kms;
                // Priority is shared across sides for the same label;
                // keep the lower (more important) value if there's drift.
                $byLabel[$label]['priority'] = min(
                    $byLabel[$label]['priority'],
                    (int) $row->priority,
                );
            }
        };
        $captureRow('wc', $wc);
        $captureRow('op', $op);

        // Sort: priority asc (pinned first), then combined kms desc.
        uasort($byLabel, function ($a, $b) {
            return $a['priority'] <=> $b['priority']
                ?: $b['combined'] <=> $a['combined'];
        });

        $wcAligned = [];
        $opAligned = [];
        foreach ($byLabel as $label => $entry) {
            foreach (['wc', 'op'] as $side) {
                $row = $entry[$side];
                if ($row === null) {
                    $row = (object) [
                        'label' => $label,
                        'kms' => 0,
                        'isk' => 0.0,
                        'priority' => $entry['priority'],
                    ];
                }
                if ($side === 'wc') {
                    $wcAligned[] = $row;
                } else {
                    $opAligned[] = $row;
                }
            }
        }

        $rollups['wc']['ship_groups'] = $wcAligned;
        $rollups['op']['ship_groups'] = $opAligned;
        return $rollups;
    }

    /**
     * Cached list of battle_theater ids that have ≥1 war-attributable
     * killmail for the given conflict. Used by the public /battles
     * filter so the Battles list scopes to whichever conflict the
     * visitor came from.
     *
     * Computed directly from killmails + killmail_attackers — does NOT
     * trigger a full buildViewData(), which is too slow (~50s for the
     * 200-day vs-initiative window) for a list-page request. The query
     * here is a single grouped scan, much cheaper.
     *
     * @return list<int>
     */
    public static function warTheaterIds(string $conflict): array
    {
        if (! isset(self::CONFLICTS[$conflict])) {
            return [];
        }
        $key = self::THEATER_IDS_CACHE_KEY . '.' . $conflict;
        return Cache::remember($key, self::VIEW_CACHE_TTL_SECONDS, function () use ($conflict): array {
            $page = new self();
            $start = self::CONFLICTS[$conflict]['start'] ?? self::WAR_START;
            $wcAlly = $page->blocAlliances(self::WINTERCO_BLOC_ID);
            $opposingAlly = $conflict === self::CONFLICT_INITIATIVE
                ? $page->inferInitiativeAlly()
                : $page->blocAlliances(self::IMPERIUM_BLOC_ID);
            if ($wcAlly === [] || $opposingAlly === []) {
                return [];
            }
            $wcStr = implode(',', $wcAlly);
            $opStr = implode(',', $opposingAlly);
            $rows = DB::select("
                SELECT DISTINCT btk.theater_id
                FROM battle_theater_killmails btk
                JOIN killmails k ON k.killmail_id = btk.killmail_id
                WHERE k.killed_at >= ?
                  AND (
                      (k.victim_alliance_id IN ($wcStr) AND EXISTS(
                          SELECT 1 FROM killmail_attackers a WHERE a.killmail_id = k.killmail_id AND a.alliance_id IN ($opStr)
                      ))
                      OR
                      (k.victim_alliance_id IN ($opStr) AND EXISTS(
                          SELECT 1 FROM killmail_attackers a WHERE a.killmail_id = k.killmail_id AND a.alliance_id IN ($wcStr)
                      ))
                  )
            ", [$start]);
            return array_values(array_map(fn ($r) => (int) $r->theater_id, $rows));
        });
    }

    /**
     * Active alliance ids for a coalition bloc.
     *
     * @return list<int>
     */
    public function blocAlliances(int $blocId): array
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
    public function inferInitiativeAlly(): array
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
                   k.victim_ship_type_id,
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
                   a.alliance_id AS alliance_id,
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
            GROUP BY a.character_id, a.alliance_id, en.name, an.name
            ORDER BY kills DESC
            LIMIT 10
        ");

        // Top pilots by losses (count + isk).
        $topPilotsLosses = DB::select("
            SELECT k.victim_character_id AS id,
                   k.victim_alliance_id AS alliance_id,
                   COALESCE(en.name, CONCAT('#', k.victim_character_id)) AS name,
                   COALESCE(an.name, '?') AS alliance_name,
                   COUNT(*) AS losses,
                   SUM(k.total_value) AS isk_lost
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            LEFT JOIN esi_entity_names en ON en.entity_id = k.victim_character_id AND en.category = 'character'
            LEFT JOIN esi_entity_names an ON an.entity_id = k.victim_alliance_id AND an.category = 'alliance'
            WHERE k.victim_character_id IS NOT NULL AND k.victim_character_id > 0
            GROUP BY k.victim_character_id, k.victim_alliance_id, en.name, an.name
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
            return ['kms' => 0, 'isk' => 0.0, 'pilots' => 0, 'lead_alliance_id' => null, 'lead_alliance_name' => null];
        }
        // Reads from the materialised _war_kms temp table built once
        // per request — see materialiseWarKillSet(). Filters by victim
        // side and the (separate) opposing-attacker set so we can
        // reuse a single shared killmail set without rebuilding it
        // for each (victim, hostile) pair.
        $victimList = implode(',', $victimAlliances);
        $hostileList = implode(',', $hostileAlliances);
        $row = DB::selectOne("
            SELECT COUNT(*) AS n,
                   COALESCE(SUM(k.total_value), 0) AS isk
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            WHERE k.victim_alliance_id IN ({$victimList})
              AND EXISTS (
                  SELECT 1 FROM killmail_attackers a
                  WHERE a.killmail_id = k.killmail_id
                    AND a.alliance_id IN ({$hostileList})
              )
        ");

        // Pilot count = distinct character_ids who appeared as
        // attackers on the war's killmails on this side. Powers the
        // "Xp" stat on the war-report VS header (mirrors the battle
        // report's per-side pilot count).
        $pilotsRow = DB::selectOne("
            SELECT COUNT(DISTINCT a.character_id) AS pilots
            FROM _war_attackers a
            WHERE a.alliance_id IN ({$victimList})
              AND a.character_id IS NOT NULL AND a.character_id > 0
        ");

        // Lead alliance for this side = whichever alliance lost the
        // most ISK over the war window. Mirrors the battle report's
        // bt-vs-chip headline pick. Falls back to most-loss-count when
        // ISK ties at 0.
        $leadRow = DB::selectOne("
            SELECT k.victim_alliance_id AS aid,
                   en.name AS name,
                   COUNT(*) AS losses,
                   COALESCE(SUM(k.total_value), 0) AS isk
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            LEFT JOIN esi_entity_names en ON en.entity_id = k.victim_alliance_id AND en.category = 'alliance'
            WHERE k.victim_alliance_id IN ({$victimList})
            GROUP BY k.victim_alliance_id, en.name
            ORDER BY isk DESC, losses DESC
            LIMIT 1
        ");

        return [
            'kms' => (int) $row->n,
            'isk' => (float) $row->isk,
            'pilots' => (int) ($pilotsRow->pilots ?? 0),
            'lead_alliance_id' => $leadRow ? (int) $leadRow->aid : null,
            'lead_alliance_name' => $leadRow && $leadRow->name ? (string) $leadRow->name : null,
        ];
    }

    /**
     * One-shot temp-table population. Holds every war-attributable
     * killmail_id. Connection-scoped — automatically dropped at end
     * of the request.
     *
     * @param  list<int>  $wcAlly
     * @param  list<int>  $hostile
     */
    /**
     * Public so external callers (the public WarEffortController for
     * the /me page) can prime the temp tables without paying for the
     * full buildViewData rollups + leaderboards + ticker round.
     */
    public function materialiseWarKillSet(string $start, array $wcAlly, array $hostile): void
    {
        $wcStr = implode(',', $wcAlly);
        $hStr = implode(',', $hostile);

        DB::statement("DROP TEMPORARY TABLE IF EXISTS _war_kms");
        DB::statement("DROP TEMPORARY TABLE IF EXISTS _war_attackers");

        // ENGINE=InnoDB so the temp tables can spill to disk if they
        // overflow tmp_table_size. The vs-initiative scope spans
        // ~7 months and produces ~1M+ _war_attackers rows, which blew
        // past the in-memory cap (16MB default) on MEMORY engine.
        // InnoDB temp is slightly slower per row but bounded by
        // disk, not RAM.
        DB::statement("
            CREATE TEMPORARY TABLE _war_kms (
                killmail_id BIGINT UNSIGNED NOT NULL,
                victim_side ENUM('wc','hostile') NOT NULL,
                PRIMARY KEY (killmail_id),
                KEY (victim_side)
            ) ENGINE=InnoDB
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
            ) ENGINE=InnoDB
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
     *   - ship_groups   every ship group lost (caps/supers/titans
     *                   pinned to top, then everything else by count)
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

        // Pinned-order priority bands:
        //   1 = Titan (group 30)
        //   2 = Supercarrier (group 659)
        //   3 = Other capitals — Carrier 547 / Dreadnought 485 /
        //       Force Auxiliary 1538 / Lancer Dread 4594 — sorted by
        //       count within
        //   4 = Subcap ships + structures, sorted by count
        // Filter scope: only category 6 (Ship) and 65 (Structure) so
        // deployables (MTU/MWD) and shuttles-the-cargo etc. don't
        // dilute the chart.
        $shipGroups = DB::select("
            SELECT COALESCE(NULLIF(k.victim_ship_group_name,''), 'Unknown') AS label,
                   COUNT(*) AS kms,
                   COALESCE(SUM(k.total_value),0) AS isk,
                   CASE
                       WHEN k.victim_ship_group_id = 30  THEN 1
                       WHEN k.victim_ship_group_id = 659 THEN 2
                       WHEN k.victim_ship_group_id IN (547, 485, 1538, 4594) THEN 3
                       ELSE 4
                   END AS priority
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            $where
              AND k.victim_ship_category_id IN (6, 65)
            GROUP BY label, priority
            ORDER BY priority ASC, kms DESC
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
                k.victim_character_id,
                k.victim_alliance_id,
                ss.name AS system_name,
                vname.name AS victim_name,
                aname.name AS victim_alliance_name,
                fb.character_id AS fb_char_id,
                fb.alliance_id AS fb_alliance_id,
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
    /**
     * Structure war scoreboard: count + ISK of upwell structures
     * (category 65) destroyed per side over the war window. The
     * "destroyed by X" totals are the mirror of the "lost by Y"
     * totals — same rule as the ISK war.
     *
     * @param  list<int>  $wcAlly
     * @param  list<int>  $hostile
     * @return array{wc: array{lost: int, isk_lost: float}, op: array{lost: int, isk_lost: float}}
     */
    private function structureWarTotals(array $wcAlly, array $hostile): array
    {
        $empty = ['wc' => ['lost' => 0, 'isk_lost' => 0.0], 'op' => ['lost' => 0, 'isk_lost' => 0.0]];
        if ($wcAlly === [] || $hostile === []) {
            return $empty;
        }
        $wcStr = implode(',', $wcAlly);
        $hStr = implode(',', $hostile);
        $rows = DB::select("
            SELECT
                CASE
                    WHEN k.victim_alliance_id IN ($wcStr) THEN 'wc'
                    WHEN k.victim_alliance_id IN ($hStr)  THEN 'op'
                    ELSE 'other'
                END AS side,
                COUNT(*) AS lost,
                COALESCE(SUM(k.total_value), 0) AS isk_lost
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            WHERE k.victim_ship_category_id = 65
            GROUP BY side
        ");
        $out = $empty;
        foreach ($rows as $r) {
            if (! in_array($r->side, ['wc', 'op'], true)) continue;
            $out[$r->side]['lost'] = (int) $r->lost;
            $out[$r->side]['isk_lost'] = (float) $r->isk_lost;
        }
        return $out;
    }

    /**
     * Systems war scoreboard: per system, whichever side landed more
     * kills on the war's killmails "wins" that system. Count of
     * systems each side dominates is the headline metric. Excludes
     * systems where the kill count tied between sides.
     *
     * @param  list<int>  $wcAlly
     * @param  list<int>  $hostile
     * @return array{wc: array{dominated: int, contested: int, total: int}, op: array{dominated: int, contested: int, total: int}}
     */
    private function systemsWarTotals(array $wcAlly, array $hostile): array
    {
        $empty = ['wc' => ['dominated' => 0, 'contested' => 0, 'total' => 0],
                  'op' => ['dominated' => 0, 'contested' => 0, 'total' => 0]];
        if ($wcAlly === [] || $hostile === []) {
            return $empty;
        }
        $wcStr = implode(',', $wcAlly);
        $hStr = implode(',', $hostile);
        // Per system: count kms where WC was the killing side
        // (victim ∈ hostile) vs Op was the killing side (victim ∈ wc).
        $rows = DB::select("
            SELECT k.solar_system_id AS sid,
                   SUM(CASE WHEN k.victim_alliance_id IN ($hStr) THEN 1 ELSE 0 END) AS wc_kills,
                   SUM(CASE WHEN k.victim_alliance_id IN ($wcStr) THEN 1 ELSE 0 END) AS op_kills
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            GROUP BY k.solar_system_id
        ");
        $wcDom = 0; $opDom = 0; $contested = 0; $total = 0;
        foreach ($rows as $r) {
            $w = (int) $r->wc_kills;
            $o = (int) $r->op_kills;
            $total++;
            if ($w > $o) {
                $wcDom++;
            } elseif ($o > $w) {
                $opDom++;
            } else {
                $contested++;
            }
        }
        return [
            'wc' => ['dominated' => $wcDom, 'contested' => $contested, 'total' => $total],
            'op' => ['dominated' => $opDom, 'contested' => $contested, 'total' => $total],
        ];
    }

    /**
     * Sov war scoreboard: how many sov-defining structures each side
     * destroyed of the OTHER side's. Sov Hub (type 32458) kills are
     * the strongest proxy we have for "system taken over" without a
     * sov-history table — when Sov Hub goes boom in a system, control
     * has typically flipped.
     *
     * Also counts current sov ownership snapshot among systems where
     * war kills happened so the operator can see the standing balance
     * even though we don't track flip history yet.
     *
     * @param  list<int>  $wcAlly
     * @param  list<int>  $hostile
     * @return array{
     *   wc: array{hubs_killed: int, hubs_lost: int, sov_now: int},
     *   op: array{hubs_killed: int, hubs_lost: int, sov_now: int}
     * }
     */
    private function sovWarTotals(array $wcAlly, array $hostile, ?string $warStart = null): array
    {
        $empty = ['wc' => ['hubs_killed' => 0, 'hubs_lost' => 0, 'sov_now' => 0,
                            'flips_gained' => 0, 'flips_lost' => 0, 'baseline_date' => null],
                  'op' => ['hubs_killed' => 0, 'hubs_lost' => 0, 'sov_now' => 0,
                            'flips_gained' => 0, 'flips_lost' => 0, 'baseline_date' => null]];
        if ($wcAlly === [] || $hostile === []) {
            return $empty;
        }
        $wcStr = implode(',', $wcAlly);
        $hStr = implode(',', $hostile);
        // Sov Hub kills (type 32458, group 1012). Count where each
        // side was the VICTIM — that's their lost sov nodes.
        $rows = DB::select("
            SELECT
                CASE
                    WHEN k.victim_alliance_id IN ($wcStr) THEN 'wc'
                    WHEN k.victim_alliance_id IN ($hStr)  THEN 'op'
                    ELSE 'other'
                END AS side,
                COUNT(*) AS lost
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            WHERE k.victim_ship_type_id = 32458
            GROUP BY side
        ");
        $wcLost = 0; $opLost = 0;
        foreach ($rows as $r) {
            if ($r->side === 'wc') $wcLost = (int) $r->lost;
            if ($r->side === 'op') $opLost = (int) $r->lost;
        }
        // Snapshot of current sov ownership in war-touched systems.
        $sovRows = DB::select("
            SELECT
                SUM(CASE WHEN ss.alliance_id IN ($wcStr) THEN 1 ELSE 0 END) AS wc_sov,
                SUM(CASE WHEN ss.alliance_id IN ($hStr)  THEN 1 ELSE 0 END) AS op_sov
            FROM (
                SELECT DISTINCT k.solar_system_id
                FROM _war_kms wk
                JOIN killmails k ON k.killmail_id = wk.killmail_id
            ) sys
            JOIN system_sovereignty ss ON ss.solar_system_id = sys.solar_system_id
        ");
        $wcNow = (int) ($sovRows[0]->wc_sov ?? 0);
        $opNow = (int) ($sovRows[0]->op_sov ?? 0);

        // Real flips computed from system_sovereignty_history. Find
        // the earliest snapshot AT or AFTER war start (or oldest
        // snapshot if war started before our history began) and diff
        // ownership against today.
        $wcGained = 0; $wcLostFlips = 0; $opGained = 0; $opLostFlips = 0;
        $baselineDate = null;
        if ($warStart !== null) {
            $baseline = DB::selectOne(
                "SELECT MIN(captured_on) AS d FROM system_sovereignty_history
                  WHERE captured_on >= ?",
                [substr($warStart, 0, 10)],
            );
            if ($baseline === null || $baseline->d === null) {
                // Fall back to oldest snapshot — better than nothing.
                $baseline = DB::selectOne("SELECT MIN(captured_on) AS d FROM system_sovereignty_history");
            }
            $baselineDate = $baseline?->d;
            if ($baselineDate !== null) {
                // Today's snapshot for diffing.
                $today = DB::selectOne("SELECT MAX(captured_on) AS d FROM system_sovereignty_history");
                if ($today !== null && $today->d !== null && $today->d !== $baselineDate) {
                    $rows = DB::select(
                        "SELECT b.solar_system_id, b.alliance_id AS base_aid, t.alliance_id AS today_aid
                         FROM system_sovereignty_history b
                         LEFT JOIN system_sovereignty_history t
                                ON t.solar_system_id = b.solar_system_id
                               AND t.captured_on = ?
                         WHERE b.captured_on = ?",
                        [$today->d, $baselineDate],
                    );
                    foreach ($rows as $r) {
                        $base = $r->base_aid !== null ? (int) $r->base_aid : 0;
                        $now  = $r->today_aid !== null ? (int) $r->today_aid : 0;
                        if ($base === $now) continue;
                        $baseSide = in_array($base, $wcAlly, true) ? 'wc' : (in_array($base, $hostile, true) ? 'op' : null);
                        $nowSide  = in_array($now, $wcAlly, true) ? 'wc' : (in_array($now, $hostile, true) ? 'op' : null);
                        if ($baseSide === 'wc' && $nowSide === 'op') { $wcLostFlips++; $opGained++; }
                        elseif ($baseSide === 'op' && $nowSide === 'wc') { $opLostFlips++; $wcGained++; }
                        // Flips to/from neutral (e.g. dropped sov) we ignore here.
                    }
                }
            }
        }

        return [
            // hubs_killed = Sov Hubs each side destroyed of the OTHER side
            'wc' => [
                'hubs_killed' => $opLost, 'hubs_lost' => $wcLost, 'sov_now' => $wcNow,
                'flips_gained' => $wcGained, 'flips_lost' => $wcLostFlips,
                'baseline_date' => $baselineDate,
            ],
            'op' => [
                'hubs_killed' => $wcLost, 'hubs_lost' => $opLost, 'sov_now' => $opNow,
                'flips_gained' => $opGained, 'flips_lost' => $opLostFlips,
                'baseline_date' => $baselineDate,
            ],
        ];
    }

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

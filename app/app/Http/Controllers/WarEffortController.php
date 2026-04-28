<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Killmails\Services\WarEffortBadges;
use App\Filament\Portal\Pages\WarReport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Public per-character war-effort view at /war-report/{conflict}/me.
 *
 * Reads the character_id stashed in the visitor's session by the
 * FLOW_WAR_STATS SSO finisher (separate from any winterco /portal
 * login). No User record, no Filament panel, no token persistence.
 *
 * Stats are computed live against killmails + killmail_attackers
 * with the per-character percentile bucketed into a 1-10 badge tier
 * via WarEffortBadges. Top tier names are EVE-flavored, bottom
 * tiers lean reddit-meme per operator.
 */
final class WarEffortController extends Controller
{
    public function show(Request $request, string $conflict): View|RedirectResponse
    {
        if (! isset(WarReport::CONFLICTS[$conflict])) {
            return redirect('/war-report');
        }

        $charId = (int) $request->session()->get('war_stats.character_id', 0);
        $charName = (string) $request->session()->get('war_stats.character_name', '');

        if ($charId <= 0) {
            // Not signed in — show the sign-in CTA. Same blade, just
            // with $signed_in = false and no stats.
            return view('public.war-effort', [
                'conflict' => $conflict,
                'opposing_label' => WarReport::CONFLICTS[$conflict]['opposing_label'],
                'opposing_tint' => WarReport::CONFLICTS[$conflict]['opposing_tint'],
                'signed_in' => false,
                'page_class' => $conflict,
                'display_label' => WarReport::displayLabel($conflict),
            ]);
        }

        // Cache the heavy compute per (conflict, character) for 5 min
        // — repeat /me hits read straight from cache, only the first
        // visit pays the temp-table + percentile cost.
        $cacheKey = "war_effort.me.v2.$conflict.$charId";
        $payload = Cache::remember($cacheKey, 300, function () use ($conflict, $charId): array {
            $stats = $this->computeStats($conflict, $charId);
            $badges = $this->resolveBadges($conflict, $stats);
            return [
                'stats' => $stats,
                'badges' => $badges,
                'overall_badge' => WarEffortBadges::overallBadge($badges),
            ];
        });
        $stats = $payload['stats'];
        $badges = $payload['badges'];
        $overallBadge = $payload['overall_badge'];

        return view('public.war-effort', [
            'conflict' => $conflict,
            'opposing_label' => WarReport::CONFLICTS[$conflict]['opposing_label'],
            'opposing_tint' => WarReport::CONFLICTS[$conflict]['opposing_tint'],
            'signed_in' => true,
            'character_id' => $charId,
            'character_name' => $charName,
            'scopes_granted' => $request->session()->get('war_stats.scopes_granted', []),
            'stats' => $stats,
            'badges' => $badges,
            'overall_badge' => $overallBadge,
            'buddy_title' => self::buddyTitle(),
            'enemy_title' => self::enemyTitle(),
            'footprint_title' => self::footprintTitle(),
            'page_class' => $conflict,
            'display_label' => WarReport::displayLabel($conflict),
        ]);
    }

    public function loading(Request $request, string $conflict): View|RedirectResponse
    {
        if (! isset(WarReport::CONFLICTS[$conflict])) {
            return redirect('/war-report');
        }
        return view('public.war-effort-loading', [
            'conflict' => $conflict,
            'opposing_label' => WarReport::CONFLICTS[$conflict]['opposing_label'],
            'opposing_tint' => WarReport::CONFLICTS[$conflict]['opposing_tint'],
            'page_class' => $conflict,
        ]);
    }

    public function logout(Request $request, string $conflict): RedirectResponse
    {
        $request->session()->forget([
            'war_stats.character_id',
            'war_stats.character_name',
            'war_stats.scopes_granted',
            'war_stats.signed_in_at',
        ]);
        if (! isset(WarReport::CONFLICTS[$conflict])) {
            $conflict = 'vs-imperium';
        }
        return redirect('/war-report/' . $conflict);
    }

    /**
     * @return array<string, mixed>
     */
    private function computeStats(string $conflict, int $charId): array
    {
        // Prime only the temp tables we need (_war_kms +
        // _war_attackers) — buildViewData also runs the rollups +
        // leaderboards + ticker which take 25-50s and we don't need
        // any of that here. Just the materialise step.
        $page = new WarReport();
        $start = WarReport::CONFLICTS[$conflict]['start'] ?? '2026-04-02 00:00:00';
        $wcAlly = $page->blocAlliances(1); // WINTERCO_BLOC_ID
        $opposingAlly = $conflict === WarReport::CONFLICT_INITIATIVE
            ? $page->inferInitiativeAlly()
            : $page->blocAlliances(3);
        $page->materialiseWarKillSet($start, $wcAlly, $opposingAlly);

        // Per-character involvement set: the distinct killmails the
        // character was an attacker on, with the total_value attached
        // once. SUM/COUNT on this gives the involvement metrics
        // without double-counting per killmail.
        $row = DB::selectOne("
            SELECT
                COUNT(*) AS kills,
                SUM(any_fb) AS final_blows,
                SUM(CASE WHEN any_fb = 1 THEN total_value ELSE 0 END) AS isk_destroyed,
                SUM(total_value) AS isk_involved,
                COUNT(DISTINCT theater_id) AS battles_attended,
                SUM(CASE WHEN attacker_count <= 5 THEN 1 ELSE 0 END) AS small_gang_kills
            FROM (
                SELECT a.killmail_id,
                       MAX(a.is_final_blow) AS any_fb,
                       k.total_value,
                       k.attacker_count,
                       MAX(btk.theater_id) AS theater_id
                FROM _war_attackers a
                JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
                JOIN killmails k ON k.killmail_id = a.killmail_id
                LEFT JOIN battle_theater_killmails btk ON btk.killmail_id = a.killmail_id
                WHERE a.character_id = ?
                  AND a.attacker_side <> wk.victim_side
                GROUP BY a.killmail_id, k.total_value, k.attacker_count
            ) per_km
        ", [$charId]);

        // Top buddies — characters most often on the same killmail as
        // attacker. Co-presence count, FBs ignored, self-excluded.
        $topBuddies = DB::select("
            SELECT a2.character_id AS id,
                   en.name AS name,
                   an.name AS alliance_name,
                   a2.alliance_id AS alliance_id,
                   COUNT(DISTINCT a1.killmail_id) AS shared_kms
            FROM _war_attackers a1
            JOIN _war_attackers a2 ON a2.killmail_id = a1.killmail_id AND a2.character_id <> a1.character_id
            JOIN _war_kms wk ON wk.killmail_id = a1.killmail_id
            LEFT JOIN esi_entity_names en ON en.entity_id = a2.character_id AND en.category = 'character'
            LEFT JOIN esi_entity_names an ON an.entity_id = a2.alliance_id AND an.category = 'alliance'
            WHERE a1.character_id = ?
              AND a1.attacker_side <> wk.victim_side
              AND a2.attacker_side = a1.attacker_side
              AND a2.character_id IS NOT NULL AND a2.character_id > 0
            GROUP BY a2.character_id, a2.alliance_id, en.name, an.name
            ORDER BY shared_kms DESC
            LIMIT 10
        ", [$charId]);

        // Top arch-enemies — opposing-side pilots who showed up in the
        // most kms involving this character (victim of mine OR attacker
        // when I was victim).
        $topEnemies = DB::select("
            SELECT cid AS id,
                   MAX(name) AS name,
                   MAX(alliance_name) AS alliance_name,
                   MAX(alliance_id) AS alliance_id,
                   SUM(n) AS encounters
            FROM (
                -- They were victims, I was attacker
                SELECT k.victim_character_id AS cid,
                       en.name AS name,
                       an.name AS alliance_name,
                       k.victim_alliance_id AS alliance_id,
                       COUNT(DISTINCT k.killmail_id) AS n
                FROM _war_attackers a
                JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
                JOIN killmails k ON k.killmail_id = a.killmail_id
                LEFT JOIN esi_entity_names en ON en.entity_id = k.victim_character_id AND en.category = 'character'
                LEFT JOIN esi_entity_names an ON an.entity_id = k.victim_alliance_id AND an.category = 'alliance'
                WHERE a.character_id = ?
                  AND a.attacker_side <> wk.victim_side
                  AND k.victim_character_id IS NOT NULL AND k.victim_character_id > 0
                GROUP BY k.victim_character_id, en.name, an.name, k.victim_alliance_id
                UNION ALL
                -- They were attackers, I was victim
                SELECT a.character_id AS cid,
                       en.name AS name,
                       an.name AS alliance_name,
                       a.alliance_id AS alliance_id,
                       COUNT(DISTINCT a.killmail_id) AS n
                FROM _war_kms wk
                JOIN killmails k ON k.killmail_id = wk.killmail_id
                JOIN _war_attackers a ON a.killmail_id = k.killmail_id
                LEFT JOIN esi_entity_names en ON en.entity_id = a.character_id AND en.category = 'character'
                LEFT JOIN esi_entity_names an ON an.entity_id = a.alliance_id AND an.category = 'alliance'
                WHERE k.victim_character_id = ?
                  AND a.character_id IS NOT NULL AND a.character_id > 0
                GROUP BY a.character_id, en.name, an.name, a.alliance_id
            ) AS combined
            GROUP BY cid
            ORDER BY encounters DESC
            LIMIT 10
        ", [$charId, $charId]);

        // Activity-map data — reuse the existing portal builder so the
        // public mirror gets the same SVG region map without a parallel
        // implementation. Scope by conflict start.
        $sinceUtc = WarReport::CONFLICTS[$conflict]['start'] ?? null;
        $activityMap = (new \App\Http\Controllers\Portal\CharacterActivityMapController())
            ->build($charId, $sinceUtc);

        // Top systems where the character fought (attacker side).
        $topSystems = DB::select("
            SELECT ss.id, ss.name, ss.security_status,
                   COUNT(DISTINCT a.killmail_id) AS kills
            FROM _war_attackers a
            JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
            JOIN killmails k ON k.killmail_id = a.killmail_id
            JOIN ref_solar_systems ss ON ss.id = k.solar_system_id
            WHERE a.character_id = ?
              AND a.attacker_side <> wk.victim_side
            GROUP BY ss.id, ss.name, ss.security_status
            ORDER BY kills DESC
            LIMIT 10
        ", [$charId]);

        $loss = DB::selectOne("
            SELECT COUNT(*) AS losses, COALESCE(SUM(k.total_value), 0) AS isk_lost
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            WHERE k.victim_character_id = ?
        ", [$charId]);

        // Total battles in this conflict across all participants —
        // denominator for the "battles attended %" stat.
        $totalBattles = DB::selectOne("
            SELECT COUNT(DISTINCT btk.theater_id) AS n
            FROM _war_kms wk
            JOIN battle_theater_killmails btk ON btk.killmail_id = wk.killmail_id
        ");

        // Killboard slices: top by ISK + most recent, both sides.
        $topIskKills = $this->charKillmailList($charId, 'attacker', 'isk', 10);
        $topIskLosses = $this->charKillmailList($charId, 'victim', 'isk', 10);
        $latestKills = $this->charKillmailList($charId, 'attacker', 'recent', 10);
        $latestLosses = $this->charKillmailList($charId, 'victim', 'recent', 10);

        return [
            'kills' => (int) $row->kills,
            'final_blows' => (int) $row->final_blows,
            'isk_destroyed' => (float) $row->isk_destroyed,
            'isk_involved' => (float) $row->isk_involved,
            'battles_attended' => (int) $row->battles_attended,
            'small_gang_kills' => (int) $row->small_gang_kills,
            'losses' => (int) $loss->losses,
            'isk_lost' => (float) $loss->isk_lost,
            'total_battles_in_conflict' => (int) $totalBattles->n,
            'battle_attendance_pct' => $totalBattles->n > 0
                ? round(((int) $row->battles_attended / (int) $totalBattles->n) * 100, 1)
                : 0.0,
            'top_systems' => $topSystems,
            'top_buddies' => $topBuddies,
            'top_enemies' => $topEnemies,
            'activity_map' => $activityMap,
            'daily_activity' => $this->dailyActivityVsAlliance($charId, $sinceUtc),
            'top_isk_kills' => $topIskKills,
            'top_isk_losses' => $topIskLosses,
            'latest_kills' => $latestKills,
            'latest_losses' => $latestLosses,
        ];
    }

    /**
     * One killmail list slice for a character — attacker or victim
     * side, ordered by ISK desc or killed_at desc, limited.
     *
     * @param  string  $side  'attacker' | 'victim'
     * @param  string  $order 'isk' | 'recent'
     * @return list<object>
     */
    private function charKillmailList(int $charId, string $side, string $order, int $limit): array
    {
        $orderBy = $order === 'isk' ? 'k.total_value DESC' : 'k.killed_at DESC';
        if ($side === 'attacker') {
            return DB::select("
                SELECT k.killmail_id, k.killed_at, k.total_value,
                       k.victim_ship_type_id, k.victim_ship_type_name,
                       k.victim_character_id, k.victim_alliance_id,
                       ss.name AS system_name,
                       en.name AS victim_name,
                       an.name AS victim_alliance_name
                FROM _war_attackers a
                JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
                JOIN killmails k ON k.killmail_id = a.killmail_id
                JOIN ref_solar_systems ss ON ss.id = k.solar_system_id
                LEFT JOIN esi_entity_names en ON en.entity_id = k.victim_character_id AND en.category = 'character'
                LEFT JOIN esi_entity_names an ON an.entity_id = k.victim_alliance_id AND an.category = 'alliance'
                WHERE a.character_id = ?
                  AND a.attacker_side <> wk.victim_side
                GROUP BY k.killmail_id, k.killed_at, k.total_value, k.victim_ship_type_id,
                         k.victim_ship_type_name, k.victim_character_id, k.victim_alliance_id,
                         ss.name, en.name, an.name
                ORDER BY $orderBy
                LIMIT $limit
            ", [$charId]);
        }
        // victim side: own losses
        return DB::select("
            SELECT k.killmail_id, k.killed_at, k.total_value,
                   k.victim_ship_type_id, k.victim_ship_type_name,
                   ss.name AS system_name,
                   fb_n.name AS fb_char_name,
                   fb.alliance_id AS fb_alliance_id,
                   fb_an.name AS fb_alliance_name
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            JOIN ref_solar_systems ss ON ss.id = k.solar_system_id
            LEFT JOIN killmail_attackers fb ON fb.killmail_id = k.killmail_id AND fb.is_final_blow = 1
            LEFT JOIN esi_entity_names fb_n ON fb_n.entity_id = fb.character_id AND fb_n.category = 'character'
            LEFT JOIN esi_entity_names fb_an ON fb_an.entity_id = fb.alliance_id AND fb_an.category = 'alliance'
            WHERE k.victim_character_id = ?
            ORDER BY $orderBy
            LIMIT $limit
        ", [$charId]);
    }

    /**
     * Full killboard view — every kill + loss for the character.
     * Linked from the /me page's "view full killboard" CTA.
     */
    public function killboard(Request $request, string $conflict): View|RedirectResponse
    {
        if (! isset(WarReport::CONFLICTS[$conflict])) {
            return redirect('/war-report');
        }
        $charId = (int) $request->session()->get('war_stats.character_id', 0);
        if ($charId <= 0) {
            return redirect('/war-report/' . $conflict . '/me');
        }
        $page = new WarReport();
        $start = WarReport::CONFLICTS[$conflict]['start'] ?? '2026-04-02 00:00:00';
        $wcAlly = $page->blocAlliances(1);
        $opposingAlly = $conflict === WarReport::CONFLICT_INITIATIVE
            ? $page->inferInitiativeAlly()
            : $page->blocAlliances(3);
        $page->materialiseWarKillSet($start, $wcAlly, $opposingAlly);

        $kills = $this->charKillmailList($charId, 'attacker', 'recent', 1000);
        $losses = $this->charKillmailList($charId, 'victim', 'recent', 1000);

        return view('public.war-effort-killboard', [
            'conflict' => $conflict,
            'opposing_label' => WarReport::CONFLICTS[$conflict]['opposing_label'],
            'opposing_tint' => WarReport::CONFLICTS[$conflict]['opposing_tint'],
            'character_id' => $charId,
            'character_name' => $request->session()->get('war_stats.character_name', ''),
            'kills' => $kills,
            'losses' => $losses,
            'page_class' => $conflict,
            'display_label' => WarReport::displayLabel($conflict),
        ]);
    }

    /**
     * Daily kill count for the character vs the per-pilot average for
     * their alliance. Two equal-length series indexed by date so the
     * SVG line-chart in blade can iterate once.
     *
     * @return array{days: list<string>, self: list<int>, alliance_avg: list<float>, alliance_id: int|null, alliance_name: string|null}
     */
    private function dailyActivityVsAlliance(int $charId, ?string $sinceUtc): array
    {
        $allianceRow = DB::selectOne("
            SELECT a.alliance_id AS id, en.name
            FROM _war_attackers a
            LEFT JOIN esi_entity_names en ON en.entity_id = a.alliance_id AND en.category = 'alliance'
            WHERE a.character_id = ? AND a.alliance_id > 0
            ORDER BY a.killmail_id DESC LIMIT 1
        ", [$charId]);
        $allianceId = $allianceRow ? (int) $allianceRow->id : null;
        $allianceName = $allianceRow ? (string) ($allianceRow->name ?? '#'.$allianceRow->id) : null;

        $self = DB::select("
            SELECT DATE(k.killed_at) AS day, COUNT(DISTINCT a.killmail_id) AS kms
            FROM _war_attackers a
            JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
            JOIN killmails k ON k.killmail_id = a.killmail_id
            WHERE a.character_id = ? AND a.attacker_side <> wk.victim_side
            GROUP BY DATE(k.killed_at) ORDER BY day ASC
        ", [$charId]);

        $alliance = [];
        if ($allianceId !== null) {
            $alliance = DB::select("
                SELECT DATE(k.killed_at) AS day,
                       COUNT(DISTINCT a.killmail_id) / GREATEST(COUNT(DISTINCT a.character_id), 1) AS avg_kms
                FROM _war_attackers a
                JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
                JOIN killmails k ON k.killmail_id = a.killmail_id
                WHERE a.alliance_id = ? AND a.attacker_side <> wk.victim_side
                GROUP BY DATE(k.killed_at) ORDER BY day ASC
            ", [$allianceId]);
        }

        // Build day-aligned series. Walk every day from $sinceUtc to
        // today; absent rows zero-fill so line-chart cells line up.
        $start = $sinceUtc !== null ? new \DateTimeImmutable($sinceUtc) : (new \DateTimeImmutable())->modify('-30 days');
        $end = new \DateTimeImmutable('today');
        $selfMap = [];
        foreach ($self as $r) $selfMap[(string) $r->day] = (int) $r->kms;
        $allMap = [];
        foreach ($alliance as $r) $allMap[(string) $r->day] = (float) $r->avg_kms;

        $days = [];
        $selfSeries = [];
        $allSeries = [];
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $days[] = $key;
            $selfSeries[] = $selfMap[$key] ?? 0;
            $allSeries[] = $allMap[$key] ?? 0.0;
        }

        return [
            'days' => $days,
            'self' => $selfSeries,
            'alliance_avg' => $allSeries,
            'alliance_id' => $allianceId,
            'alliance_name' => $allianceName,
        ];
    }

    /**
     * Reddit-meme rotating banner for the arch-enemies section. Picked
     * randomly per visit to keep things fresh.
     *
     * @return list<string>
     */
    /** @var list<string> */
    public const array FOOTPRINT_TITLES = [
        'Your War Footprint',
        'Where The Bodies Lie',
        'Crime Scenes',
        'Systems You Have Personally Ruined',
        'Local Spike Locations',
        'My Yard',
        'The Roam Diaries',
        'Wrecks Per Postcode',
        'Where Did You Get Caught',
        'The Streets You Run',
    ];

    public static function footprintTitle(): string
    {
        return self::FOOTPRINT_TITLES[array_rand(self::FOOTPRINT_TITLES)];
    }

    /** @var list<string> */
    public const array BUDDY_TITLES = [
        'Your Crusty Crew',
        'Ride or Dies',
        'The Bois',
        'Discord Voice Chat Survivors',
        'Fleet-Anchor Best Friends',
        'My Wingmen',
        'The Brain Trust',
        'Frens Forever',
        'On The Same Mailing List',
        'They Lend You Ammo',
    ];

    public static function buddyTitle(): string
    {
        return self::BUDDY_TITLES[array_rand(self::BUDDY_TITLES)];
    }

    public const array ENEMY_TITLES = [
        'Arch Nemeses Hall of Fame',
        'They Have Personally Wronged You',
        'My Sworn Beefs',
        'Names In My Notepad',
        'The Touch-Grass Brigade',
        'Permanent Standings: -10',
        'On Sight',
        'The Group Chat',
        'My Therapist Knows Their Names',
        'Built Different (Mostly Dead)',
    ];

    public static function enemyTitle(): string
    {
        return self::ENEMY_TITLES[array_rand(self::ENEMY_TITLES)];
    }

    /**
     * Per-metric: compute the character's percentile rank vs every
     * other war participant, then map to a tier 1-10 via the badge
     * ladder. Heavy bucket of distribution data is cached 10 min
     * keyed by (conflict, metric) — same conflict is consulted by
     * many visitors so the percentile tables are reusable.
     *
     * @param  array<string, mixed>  $stats
     * @return array<string, array{metric: string, value: int|float, percentile: float, tier: int, name: string, sub: string}>
     */
    private function resolveBadges(string $conflict, array $stats): array
    {
        $out = [];
        foreach (WarEffortBadges::METRICS as $metric) {
            $value = (float) ($stats[$metric] ?? 0);
            $distribution = $this->participantDistribution($conflict, $metric);
            $percentile = $this->percentileRankFromBetter($distribution, $value);
            $tier = WarEffortBadges::tierForPercentile($percentile);
            $ladder = WarEffortBadges::ladder($metric);

            // Compute the value needed to reach the next (better) tier.
            // Tier 1 has no next; tiers 2-10 → look up the cutoff at
            // tier-1's percentile and find the value at that rank in
            // the distribution.
            $nextTier = max(1, $tier - 1);
            $nextDelta = null;
            $nextThreshold = null;
            $nextName = null;
            if ($tier > 1) {
                $nextCutoff = WarEffortBadges::TIER_PERCENTILES[$nextTier - 1] ?? null;
                if ($nextCutoff !== null && count($distribution) > 0) {
                    // Distribution is ascending; "top X%" = highest
                    // X% of values. Find the threshold value at
                    // (1 - cutoff/100) * N.
                    $n = count($distribution);
                    $idx = max(0, min($n - 1, (int) floor($n * (1 - $nextCutoff / 100))));
                    $nextThreshold = $distribution[$idx];
                    $nextDelta = max(0.0, $nextThreshold - $value);
                    $nextName = $ladder[$nextTier]['name'] ?? null;
                }
            }

            $out[$metric] = [
                'metric' => $metric,
                'value' => $value,
                'percentile' => $percentile,
                'tier' => $tier,
                'name' => $ladder[$tier]['name'],
                'sub' => $ladder[$tier]['sub'],
                'next_tier' => $tier > 1 ? $nextTier : null,
                'next_name' => $nextName,
                'next_threshold' => $nextThreshold,
                'next_delta' => $nextDelta,
            ];
        }
        return $out;
    }

    /**
     * Sorted list of every participant's value for the given metric.
     * Cached so we only run the heavy aggregate once per (conflict,
     * metric) per 10 minutes.
     *
     * @return list<float>
     */
    private function participantDistribution(string $conflict, string $metric): array
    {
        $key = sprintf('war_effort.dist.%s.%s.v1', $conflict, $metric);
        return Cache::remember($key, 600, function () use ($metric): array {
            $sql = match ($metric) {
                'kills' => "
                    SELECT COUNT(DISTINCT a.killmail_id) AS v
                    FROM _war_attackers a
                    JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
                    WHERE a.character_id IS NOT NULL AND a.character_id > 0
                      AND a.attacker_side <> wk.victim_side
                    GROUP BY a.character_id
                ",
                'final_blows' => "
                    SELECT SUM(CASE WHEN a.is_final_blow = 1 THEN 1 ELSE 0 END) AS v
                    FROM _war_attackers a
                    JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
                    WHERE a.character_id IS NOT NULL AND a.character_id > 0
                      AND a.attacker_side <> wk.victim_side
                    GROUP BY a.character_id
                ",
                'isk_destroyed' => "
                    SELECT SUM(CASE WHEN a.is_final_blow = 1 THEN k.total_value ELSE 0 END) AS v
                    FROM _war_attackers a
                    JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
                    JOIN killmails k ON k.killmail_id = a.killmail_id
                    WHERE a.character_id IS NOT NULL AND a.character_id > 0
                      AND a.attacker_side <> wk.victim_side
                    GROUP BY a.character_id
                ",
                'battles_attended' => "
                    SELECT COUNT(DISTINCT btk.theater_id) AS v
                    FROM _war_attackers a
                    JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
                    LEFT JOIN battle_theater_killmails btk ON btk.killmail_id = a.killmail_id
                    WHERE a.character_id IS NOT NULL AND a.character_id > 0
                      AND a.attacker_side <> wk.victim_side
                    GROUP BY a.character_id
                ",
                'small_gang_kills' => "
                    SELECT COUNT(DISTINCT CASE WHEN k.attacker_count <= 5 THEN a.killmail_id END) AS v
                    FROM _war_attackers a
                    JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
                    JOIN killmails k ON k.killmail_id = a.killmail_id
                    WHERE a.character_id IS NOT NULL AND a.character_id > 0
                      AND a.attacker_side <> wk.victim_side
                    GROUP BY a.character_id
                ",
                default => null,
            };
            if ($sql === null) return [];
            $rows = DB::select($sql);
            $values = array_map(fn ($r) => (float) $r->v, $rows);
            sort($values);
            return $values;
        });
    }

    /**
     * Lower percentile = better rank. value 100 with distribution
     * mostly < 100 → percentile near 0 (top). The +1 / N+1 keeps the
     * top sample from collapsing to 0.
     *
     * @param  list<float>  $sorted  ascending distribution
     */
    private function percentileRankFromBetter(array $sorted, float $value): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 100.0;
        }
        // Index of values strictly greater than the input.
        $better = 0;
        for ($i = $n - 1; $i >= 0; $i--) {
            if ($sorted[$i] > $value) {
                $better++;
            } else {
                break;
            }
        }
        return round(($better / $n) * 100, 2);
    }
}

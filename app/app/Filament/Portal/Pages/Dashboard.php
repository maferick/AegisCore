<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Domains\CounterIntel\Services\CharacterGraphInsightService;
use App\Domains\CounterIntel\Services\CounterIntelDossierService;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $title = 'Overview';

    protected ?string $heading = 'My Overview';

    protected ?string $subheading = 'Character summary and recent activity.';

    protected string $view = 'filament.portal.pages.dashboard';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Auth::user();
        if ($user === null) {
            return ['characters' => [], 'data_since' => null];
        }
        $characters = $user->characters()->get();
        $cards = [];
        foreach ($characters as $char) {
            $cards[] = $this->buildCharacterCard($char);
        }
        // Earliest killmail we have — bounds every per-character stat
        // below ("X kills since …"). Cached for a day so this doesn't
        // scan on every dashboard load.
        $dataSince = cache()->remember('dashboard.killmail.min_killed_at', 86400, function (): ?string {
            $v = DB::table('killmails')->min('killed_at');
            return $v ? (string) $v : null;
        });
        return ['characters' => $cards, 'data_since' => $dataSince];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCharacterCard(object $char): array
    {
        $cid = (int) $char->character_id;

        // 5-minute card cache. buildCharacterCard executes 12+ heavy
        // queries (history scans, hour histogram, top systems, fought
        // with/against, neo4j cypher) per render. Same character is
        // re-rendered hundreds of times per day across the operator's
        // /me view + every char-lookup hit + every CI command card.
        // 300s freshness is well within the daily fusion / projection
        // cadence and any signal that needs faster propagation has
        // its own surface (CI command auto-refresh is hourly).
        // Bust by appending the bloc/corp/alliance hash so a
        // mid-window defection invalidates the cached card the next
        // time the live affiliation flips.
        $cacheKey = sprintf(
            'dashboard.char.%d.v1.%d.%d',
            $cid,
            (int) ($char->corporation_id ?? 0),
            (int) ($char->alliance_id ?? 0),
        );
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $currentCorpId = (int) ($char->corporation_id ?? 0) ?: null;
        $currentAllyId = (int) ($char->alliance_id ?? 0) ?: null;

        // Entity names for current + historical affiliation.
        $entityIds = array_filter([$cid, $currentCorpId, $currentAllyId]);
        $names = DB::table('esi_entity_names')
            ->whereIn('entity_id', $entityIds)
            ->pluck('name', 'entity_id')
            ->all();

        // Corporation history, newest first. Each row: character was in
        // corp X from start_date to end_date (null = current). For every
        // corp, ask CorporationAllianceHistory which alliance that corp
        // was in at the start_date of the character's membership —
        // gives the "alliance at that time" timeline the operator
        // actually cares about.
        $corpHist = DB::table('character_corporation_history')
            ->where('character_id', $cid)
            ->where('is_deleted', 0)
            ->orderByDesc('start_date')
            ->select('corporation_id', 'start_date', 'end_date')
            ->get();

        $corpIds = $corpHist->pluck('corporation_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        if ($corpIds !== []) {
            $corpNames = DB::table('esi_entity_names')
                ->whereIn('entity_id', $corpIds)
                ->where('category', 'corporation')
                ->pluck('name', 'entity_id')
                ->all();
        } else {
            $corpNames = [];
        }

        // Alliance-at-time lookup. Reuse CorporationAllianceHistory.
        $timelineAlliances = [];
        foreach ($corpHist as $row) {
            $startTs = $row->start_date;
            $corpId = (int) $row->corporation_id;
            $allyRow = DB::table('corporation_alliance_history')
                ->where('corporation_id', $corpId)
                ->where('start_date', '<=', $startTs)
                ->where(function ($q) use ($startTs): void {
                    $q->whereNull('end_date')->orWhere('end_date', '>=', $startTs);
                })
                ->orderByDesc('start_date')
                ->first();
            $aid = $allyRow && $allyRow->alliance_id ? (int) $allyRow->alliance_id : null;
            $aname = null;
            if ($aid !== null) {
                $aname = DB::table('esi_entity_names')
                    ->where('entity_id', $aid)
                    ->where('category', 'alliance')
                    ->value('name');
            }
            $timelineAlliances[] = [
                'corp_id' => $corpId,
                'corp_name' => $corpNames[$corpId] ?? "Corp #{$corpId}",
                'start_date' => $startTs,
                'end_date' => $row->end_date,
                'alliance_id' => $aid,
                'alliance_name' => $aname,
            ];
        }

        // Distinct alliances chronologically (collapse repeated corp→same ally).
        $distinctAlliances = [];
        $prevAid = null;
        foreach (array_reverse($timelineAlliances) as $row) {
            if ($row['alliance_id'] === null) continue;
            if ($row['alliance_id'] === $prevAid) continue;
            $distinctAlliances[] = [
                'alliance_id' => $row['alliance_id'],
                'alliance_name' => $row['alliance_name'] ?? "#{$row['alliance_id']}",
                'first_seen' => $row['start_date'],
            ];
            $prevAid = $row['alliance_id'];
        }
        // Newest first for display.
        $distinctAlliances = array_reverse($distinctAlliances);

        // Kill stats from killmails — cheap counts.
        $kills = DB::table('killmail_attackers')
            ->where('character_id', $cid)
            ->count();
        $losses = DB::table('killmails')
            ->where('victim_character_id', $cid)
            ->count();

        // Top 3 hulls flown (attacker rows).
        $topHulls = DB::table('killmail_attackers AS ka')
            ->leftJoin('ref_item_types AS rit', 'rit.id', '=', 'ka.ship_type_id')
            ->where('ka.character_id', $cid)
            ->whereNotNull('ka.ship_type_id')
            ->selectRaw('ka.ship_type_id, rit.name, COUNT(*) AS n')
            ->groupBy('ka.ship_type_id', 'rit.name')
            ->orderByDesc('n')
            ->limit(3)
            ->get()
            ->map(fn ($r) => [
                'type_id' => (int) $r->ship_type_id,
                'name' => (string) ($r->name ?? "type {$r->ship_type_id}"),
                'n' => (int) $r->n,
            ])
            ->all();

        // Highlights: biggest kill / biggest loss + rolled ISK totals +
        // solo kills + largest gang kill.
        $biggestKill = DB::table('killmails AS k')
            ->join('killmail_attackers AS ka', 'ka.killmail_id', '=', 'k.killmail_id')
            ->leftJoin('ref_item_types AS rit', 'rit.id', '=', 'k.victim_ship_type_id')
            ->where('ka.character_id', $cid)
            ->orderByDesc('k.total_value')
            ->limit(1)
            ->selectRaw('k.killmail_id, k.total_value, k.victim_ship_type_id, rit.name AS ship_name, k.killed_at')
            ->first();

        $biggestLoss = DB::table('killmails AS k')
            ->leftJoin('ref_item_types AS rit', 'rit.id', '=', 'k.victim_ship_type_id')
            ->where('k.victim_character_id', $cid)
            ->orderByDesc('k.total_value')
            ->limit(1)
            ->selectRaw('k.killmail_id, k.total_value, k.victim_ship_type_id, rit.name AS ship_name, k.killed_at')
            ->first();

        // Total ISK destroyed — full kill value per killmail the pilot
        // was on, not damage-share weighted. Tackle / logi / ewar /
        // bomber-assist pilots contribute zero damage but they're what
        // let the kill happen; crediting only damage dealers erases
        // every non-DPS role. Matches zKillboard convention.
        $iskDestroyedRaw = DB::table('killmails AS k')
            ->whereIn('k.killmail_id', function ($q) use ($cid): void {
                $q->select('killmail_id')
                    ->from('killmail_attackers')
                    ->where('character_id', $cid);
            })
            ->sum('k.total_value');

        $iskLostRaw = DB::table('killmails')
            ->where('victim_character_id', $cid)
            ->sum('total_value');

        $soloKills = DB::table('killmails AS k')
            ->join('killmail_attackers AS ka', 'ka.killmail_id', '=', 'k.killmail_id')
            ->where('ka.character_id', $cid)
            ->where('k.attacker_count', 1)
            ->count();

        $largestGang = DB::table('killmails AS k')
            ->join('killmail_attackers AS ka', 'ka.killmail_id', '=', 'k.killmail_id')
            ->where('ka.character_id', $cid)
            ->max('k.attacker_count');

        // Final blows attributed to this pilot.
        $finalBlows = DB::table('killmail_attackers')
            ->where('character_id', $cid)
            ->where('is_final_blow', 1)
            ->count();

        // Pod losses — victim rows where victim group id = 29 (Capsule).
        $podLosses = DB::table('killmails')
            ->where('victim_character_id', $cid)
            ->where('victim_ship_group_id', 29)
            ->count();

        // Kills contributed to on capital-class hulls (not this pilot's
        // ship, the VICTIM's). Dread/Carrier/Super/Titan/FAX/Rorqual.
        $capitalKills = DB::table('killmail_attackers AS ka')
            ->join('killmails AS k', 'k.killmail_id', '=', 'ka.killmail_id')
            ->where('ka.character_id', $cid)
            ->whereIn('k.victim_ship_group_id', [485, 547, 659, 30, 1538, 883])
            ->count();

        // First + last killmail — activity span.
        $span = DB::table('killmail_attackers AS ka')
            ->join('killmails AS k', 'k.killmail_id', '=', 'ka.killmail_id')
            ->where('ka.character_id', $cid)
            ->selectRaw('MIN(k.killed_at) AS first_km, MAX(k.killed_at) AS last_km')
            ->first();
        $firstKm = $span->first_km ?? null;
        $lastKm = $span->last_km ?? null;
        // Include losses as activity markers too.
        if ($losses > 0) {
            $lossSpan = DB::table('killmails')
                ->where('victim_character_id', $cid)
                ->selectRaw('MIN(killed_at) AS first_loss, MAX(killed_at) AS last_loss')
                ->first();
            if ($lossSpan->first_loss && (!$firstKm || $lossSpan->first_loss < $firstKm)) $firstKm = $lossSpan->first_loss;
            if ($lossSpan->last_loss && (!$lastKm || $lossSpan->last_loss > $lastKm)) $lastKm = $lossSpan->last_loss;
        }

        // Hour-of-day histogram (UTC) across all kills pilot appears on.
        $hourRows = DB::select(<<<'SQL'
            SELECT HOUR(k.killed_at) AS h, COUNT(*) AS n FROM (
                SELECT killmail_id FROM killmail_attackers WHERE character_id=?
                UNION
                SELECT killmail_id FROM killmails WHERE victim_character_id=?
            ) mine JOIN killmails k ON k.killmail_id=mine.killmail_id
            GROUP BY HOUR(k.killed_at)
        SQL, [$cid, $cid]);
        $hourHistogram = array_fill(0, 24, 0);
        foreach ($hourRows as $r) {
            $hourHistogram[(int) $r->h] = (int) $r->n;
        }

        // ISK efficiency — percentage of "destroyed + lost" credited to
        // the pilot's side. zKill convention.
        $iskEff = null;
        $iskTotal = (float) $iskDestroyedRaw + (float) $iskLostRaw;
        if ($iskTotal > 0) {
            $iskEff = round(((float) $iskDestroyedRaw / $iskTotal) * 100, 1);
        }

        // Role breakdown from killmail_pilot_role (per-km hull-based role).
        $roleBreakdown = DB::table('killmail_pilot_role')
            ->where('character_id', $cid)
            ->whereIn('role_key', ['fc', 'logi', 'bomber', 'command', 'tackle', 'mainline_dps'])
            ->selectRaw('role_key, COUNT(*) AS n')
            ->groupBy('role_key')
            ->orderByDesc('n')
            ->get()
            ->map(fn ($r) => ['role' => (string) $r->role_key, 'n' => (int) $r->n])
            ->all();
        $roleTotal = array_sum(array_column($roleBreakdown, 'n')) ?: 1;

        // Fleet sessions. The theater-clustering worker is lossy — on
        // a cold pipeline this count reads as 3 for an FC with
        // hundreds of kills. Count session-based instead: pull every
        // killmail the pilot appeared on (attacker or victim),
        // sort chronologically, start a new session on any gap
        // > 1 hour. Each session ≈ one fleet op.
        // Indexed UNION path — each leg hits a covering index, no
        // 7M-row LEFT JOIN scan.
        $sessionRows = DB::select(<<<'SQL'
            SELECT t FROM (
              SELECT k.killed_at AS t
                FROM killmail_attackers ka
                JOIN killmails k ON k.killmail_id = ka.killmail_id
               WHERE ka.character_id = ?
              UNION
              SELECT killed_at AS t
                FROM killmails
               WHERE victim_character_id = ?
            ) u ORDER BY t
        SQL, [$cid, $cid]);
        $fleetSessions = 0;
        $prevTs = null;
        foreach ($sessionRows as $row) {
            $ts = strtotime((string) $row->t);
            if ($prevTs === null || ($ts - $prevTs) > 3600) {
                $fleetSessions++;
            }
            $prevTs = $ts;
        }
        $battlesParticipated = $fleetSessions;

        // Activity map is lazy-loaded via /portal/characters/{cid}/activity-map
        // so the dashboard render doesn't wait on BFS + titan pair
        // queries. Blade injects a placeholder that fetches the SVG
        // partial after page paint.

        // Top 3 systems — kills-on + losses-in weighted equally.
        $topSystems = DB::select(<<<'SQL'
            SELECT sys.system_id, s.name, SUM(sys.n) AS n FROM (
                SELECT k.solar_system_id AS system_id, COUNT(*) AS n
                  FROM killmail_attackers ka JOIN killmails k ON k.killmail_id=ka.killmail_id
                 WHERE ka.character_id=? GROUP BY k.solar_system_id
                UNION ALL
                SELECT solar_system_id AS system_id, COUNT(*) AS n
                  FROM killmails WHERE victim_character_id=? GROUP BY solar_system_id
            ) sys LEFT JOIN ref_solar_systems s ON s.id = sys.system_id
            GROUP BY sys.system_id, s.name
            ORDER BY n DESC LIMIT 3
        SQL, [$cid, $cid]);

        // Most fought WITH — alliances that appeared alongside this
        // pilot on the same killmails (attacker-side co-occurrence).
        // Excludes the pilot's own alliance so the result reads as
        // "you flew with these" not "you're in this alliance".
        // Two-stage: aggregate by PEER CHARACTER, then map each peer
        // to their CURRENT alliance via character_corporation_history.
        // The legacy single-query version grouped by ka2.alliance_id
        // (kill-time snapshot), which surfaced the peer's old alliance
        // long after they defected (2026-04-30 incident: Bakkanta
        // pilots showed Dracarys after their move to Insidious).
        // Non-character co-attackers (NPCs, structures) keep the
        // kill-time alliance because they have no current to look up.
        $foughtWithRaw = DB::select(<<<'SQL'
            SELECT ka2.character_id AS peer_cid,
                   ka2.alliance_id AS peer_aid_at_kill,
                   COUNT(DISTINCT ka.killmail_id) AS n
              FROM killmail_attackers ka
              JOIN killmail_attackers ka2
                ON ka2.killmail_id = ka.killmail_id
               AND ka2.alliance_id IS NOT NULL
             WHERE ka.character_id = ?
               AND (ka2.character_id IS NULL OR ka2.character_id <> ?)
             GROUP BY ka2.character_id, ka2.alliance_id
        SQL, [$cid, $cid]);
        $foughtWith = $this->aggregateByCurrentAlliance($foughtWithRaw, $currentAllyId);

        // Most fought AGAINST — victim alliance on kills the pilot
        // participated in. Same two-stage rewrite: aggregate by
        // VICTIM CHARACTER (or fall back to victim_alliance_id when
        // the victim wasn't a character — structures), then resolve
        // current alliance per peer.
        $foughtAgainstRaw = DB::select(<<<'SQL'
            SELECT k.victim_character_id AS peer_cid,
                   k.victim_alliance_id  AS peer_aid_at_kill,
                   COUNT(*) AS n
              FROM killmail_attackers ka
              JOIN killmails k ON k.killmail_id = ka.killmail_id
             WHERE ka.character_id = ?
               AND k.victim_alliance_id IS NOT NULL
             GROUP BY k.victim_character_id, k.victim_alliance_id
        SQL, [$cid]);
        $foughtAgainst = $this->aggregateByCurrentAlliance($foughtAgainstRaw, $currentAllyId);

        // Neo4j insights — best-effort, null-safe if Neo4j is down.
        $insights = app(CharacterGraphInsightService::class);
        $flightCrew = $insights->flightCrew($cid, 12) ?? [];
        $archEnemies = $insights->archEnemies($cid, 12) ?? [];
        $structRank = $insights->structuralRank($cid);

        // Collate alliance ids from every source that needs name lookup.
        $mergedAllyIds = array_values(array_unique(array_merge(
            array_map(fn ($r) => (int) $r->alliance_id, $foughtWith),
            array_map(fn ($r) => (int) $r->alliance_id, $foughtAgainst),
            array_filter(array_column($flightCrew, 'alliance_id')),
            array_filter(array_column($archEnemies, 'alliance_id')),
        )));
        $allyNameLookup = [];
        if ($mergedAllyIds !== []) {
            $allyNameLookup = DB::table('esi_entity_names')
                ->whereIn('entity_id', $mergedAllyIds)
                ->where('category', 'alliance')
                ->pluck('name', 'entity_id')
                ->all();
        }
        // Attach alliance names onto graph-insight rows.
        foreach ($flightCrew as &$row) {
            $row['alliance_name'] = $row['alliance_id'] ? ($allyNameLookup[$row['alliance_id']] ?? null) : null;
        }
        unset($row);
        foreach ($archEnemies as &$row) {
            $row['alliance_name'] = $row['alliance_id'] ? ($allyNameLookup[$row['alliance_id']] ?? null) : null;
        }
        unset($row);

        $highlights = [
            'biggest_kill' => $biggestKill ? [
                'killmail_id' => (int) $biggestKill->killmail_id,
                'isk' => (float) $biggestKill->total_value,
                'ship_id' => (int) $biggestKill->victim_ship_type_id,
                'ship_name' => (string) ($biggestKill->ship_name ?? "type {$biggestKill->victim_ship_type_id}"),
                'killed_at' => (string) $biggestKill->killed_at,
            ] : null,
            'biggest_loss' => $biggestLoss ? [
                'killmail_id' => (int) $biggestLoss->killmail_id,
                'isk' => (float) $biggestLoss->total_value,
                'ship_id' => (int) $biggestLoss->victim_ship_type_id,
                'ship_name' => (string) ($biggestLoss->ship_name ?? "type {$biggestLoss->victim_ship_type_id}"),
                'killed_at' => (string) $biggestLoss->killed_at,
            ] : null,
            'isk_destroyed' => (float) $iskDestroyedRaw,
            'isk_lost' => (float) $iskLostRaw,
            'solo_kills' => (int) $soloKills,
            'largest_gang' => $largestGang ? (int) $largestGang : null,
            'final_blows' => (int) $finalBlows,
            'pod_losses' => (int) $podLosses,
            'capital_kills' => (int) $capitalKills,
            'isk_efficiency' => $iskEff,
            'first_km' => $firstKm,
            'last_km' => $lastKm,
        ];

        // Counter-Intel section. Dossier is per-(character, viewer_bloc),
        // 10min-cached internally by the service. Render-time evidence
        // strings live in the service so we can change wording without
        // recomputing anything. Returns null if the viewer has no
        // resolvable bloc (operator has no main alliance) — in which
        // case the blade just hides the section.
        $counterIntel = null;
        $viewerBlocId = $this->resolveViewerBlocId();
        if ($viewerBlocId !== null) {
            try {
                $counterIntel = app(CounterIntelDossierService::class)->dossier($cid, $viewerBlocId);
            } catch (\Throwable $e) {
                $counterIntel = null;
            }
        }

        $card = [
            'character_id' => $cid,
            'character_name' => $names[$cid] ?? $char->character_name ?? "Pilot #{$cid}",
            'corporation_id' => $currentCorpId,
            'corporation_name' => $currentCorpId ? ($names[$currentCorpId] ?? null) : null,
            'alliance_id' => $currentAllyId,
            'alliance_name' => $currentAllyId ? ($names[$currentAllyId] ?? null) : null,
            'alliances_timeline' => $distinctAlliances,
            'corp_timeline' => $timelineAlliances,
            'kills' => $kills,
            'losses' => $losses,
            'top_hulls' => $topHulls,
            'highlights' => $highlights,
            'role_breakdown' => $roleBreakdown,
            'role_total' => $roleTotal,
            'battles_participated' => $battlesParticipated,
            'hour_histogram' => $hourHistogram,
            'top_systems' => array_map(fn ($r) => [
                'system_id' => (int) $r->system_id,
                'name' => (string) ($r->name ?? "#{$r->system_id}"),
                'n' => (int) $r->n,
            ], $topSystems),
            'fought_with' => array_map(fn ($r) => [
                'alliance_id' => (int) $r->alliance_id,
                'name' => (string) ($allyNameLookup[$r->alliance_id] ?? "#{$r->alliance_id}"),
                'n' => (int) $r->n,
            ], $foughtWith),
            'fought_against' => array_map(fn ($r) => [
                'alliance_id' => (int) $r->alliance_id,
                'name' => (string) ($allyNameLookup[$r->alliance_id] ?? "#{$r->alliance_id}"),
                'n' => (int) $r->n,
            ], $foughtAgainst),
            'flight_crew' => $flightCrew,
            'arch_enemies' => $archEnemies,
            'structural_rank' => $structRank,
            'counter_intel' => $counterIntel,
            'viewer_bloc_id' => $viewerBlocId,
        ];

        Cache::put($cacheKey, $card, 300);
        return $card;
    }

    /**
     * Re-aggregate raw (peer_cid, peer_aid_at_kill, n) rows so the
     * grouping key becomes the peer's CURRENT alliance instead of
     * the kill-time snapshot. Caller already excluded the viewer's
     * own character; we filter the viewer's *current* alliance here
     * because the kill-time row may have a peer-now-ally entry that
     * wouldn't have been excluded by the original SQL filter.
     *
     * @param  list<object>  $rows
     * @return list<array{alliance_id: int, n: int}>
     */
    private function aggregateByCurrentAlliance(array $rows, ?int $excludeAllianceId): array
    {
        if ($rows === []) return [];

        $peerCids = array_values(array_unique(array_filter(
            array_map(static fn ($r) => isset($r->peer_cid) ? (int) $r->peer_cid : 0, $rows),
        )));

        $currentByCid = $peerCids === []
            ? []
            : $this->resolveCurrentAllianceMap($peerCids);

        $tally = [];
        foreach ($rows as $r) {
            $peerCid = isset($r->peer_cid) ? (int) $r->peer_cid : 0;
            // Prefer current alliance from the live affiliation cache.
            // Fall back to kill-time snapshot when the peer is non-
            // character (structure / NPC) or when we have no tracked
            // history for them.
            $aid = $peerCid > 0 && isset($currentByCid[$peerCid])
                ? $currentByCid[$peerCid]
                : (int) ($r->peer_aid_at_kill ?? 0);
            if ($aid <= 0) continue;
            if ($excludeAllianceId !== null && $aid === $excludeAllianceId) continue;
            $tally[$aid] = ($tally[$aid] ?? 0) + (int) $r->n;
        }

        arsort($tally, SORT_NUMERIC);

        $out = [];
        foreach ($tally as $aid => $n) {
            $out[] = ['alliance_id' => (int) $aid, 'n' => (int) $n];
            if (count($out) >= 3) break;
        }

        return array_map(static fn (array $row) => (object) $row, $out);
    }

    /**
     * Map character_id → current alliance_id from
     * character_corporation_history → corporation_alliance_history
     * (live affiliation cache). Mirrors the helper on
     * CharacterGraphInsightService so both surfaces resolve the
     * same way.
     *
     * @param  list<int>  $cids
     * @return array<int, int>
     */
    private function resolveCurrentAllianceMap(array $cids): array
    {
        if ($cids === []) return [];

        $corpRows = DB::table('character_corporation_history')
            ->whereIn('character_id', $cids)
            ->where('is_deleted', 0)
            ->whereNull('end_date')
            ->select('character_id', 'corporation_id', 'start_date')
            ->orderBy('character_id')
            ->orderByDesc('start_date')
            ->get();

        $corpByCid = [];
        foreach ($corpRows as $r) {
            $cid = (int) $r->character_id;
            if (! isset($corpByCid[$cid])) {
                $corpByCid[$cid] = (int) $r->corporation_id;
            }
        }
        if ($corpByCid === []) return [];

        $corpIds = array_values(array_unique(array_values($corpByCid)));
        $allianceRows = DB::table('corporation_alliance_history')
            ->whereIn('corporation_id', $corpIds)
            ->whereNull('end_date')
            ->select('corporation_id', 'alliance_id', 'start_date')
            ->orderBy('corporation_id')
            ->orderByDesc('start_date')
            ->get();

        $aidByCorp = [];
        foreach ($allianceRows as $r) {
            $corp = (int) $r->corporation_id;
            if (isset($aidByCorp[$corp])) continue;
            if ($r->alliance_id) {
                $aidByCorp[$corp] = (int) $r->alliance_id;
            }
        }

        $out = [];
        foreach ($corpByCid as $cid => $corp) {
            if (isset($aidByCorp[$corp])) {
                $out[$cid] = $aidByCorp[$corp];
            }
        }
        return $out;
    }

    /**
     * Resolve the viewer's bloc id from their primary character's
     * alliance label. Mirrors CounterIntelDossier::resolveViewerBloc.
     * Null when the viewer has no alliance / no bloc tag.
     */
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

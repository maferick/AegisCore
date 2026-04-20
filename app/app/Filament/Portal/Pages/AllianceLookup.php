<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/alliances/lookup — "how is this alliance structured?"
 *
 * Per-alliance view with the chain-of-command layout:
 *   header → command layer (FCs + boosters) → core support (logi,
 *   tackle, bomber) → DPS backbone → corp breakdown → FC-crew
 *   clusters (each FC + their recurring co-flyers).
 *
 * Data sources already in place: ci_character_features_rolling for
 * per-pilot role percentages + battle counts, killmail_attackers
 * for alliance-pilot enumeration, CI_CO_OCCURS_WITH (Neo4j) for the
 * FC-crew clusters.
 */
class AllianceLookup extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'Alliance Lookup';

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Alliance Lookup';

    protected static ?string $slug = 'alliances/lookup';

    protected string $view = 'filament.portal.pages.alliance-lookup';

    public ?string $search = null;
    public ?int $allianceId = null;

    public function mount(): void
    {
        $this->search = (string) request()->query('q', '');
        $aid = request()->query('aid');
        if ($aid !== null && ctype_digit((string) $aid)) {
            $this->allianceId = (int) $aid;
        }
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $suggestions = [];
        if ($this->allianceId === null && $this->search !== null && mb_strlen($this->search) >= 3) {
            $suggestions = DB::table('esi_entity_names')
                ->where('category', 'alliance')
                ->where('name', 'like', '%'.$this->search.'%')
                ->orderBy('name')
                ->limit(30)
                ->select('entity_id', 'name')
                ->get()
                ->map(fn ($r) => ['alliance_id' => (int) $r->entity_id, 'name' => (string) $r->name])
                ->all();
        }
        if ($this->allianceId === null) {
            return [
                'search' => $this->search,
                'alliance' => null,
                'suggestions' => $suggestions,
            ];
        }

        $aid = $this->allianceId;
        $allianceName = DB::table('esi_entity_names')
            ->where('entity_id', $aid)
            ->where('category', 'alliance')
            ->value('name');

        $blocRow = DB::table('coalition_entity_labels AS cel')
            ->leftJoin('coalition_blocs AS cb', 'cb.id', '=', 'cel.bloc_id')
            ->leftJoin('coalition_relationship_types AS crt', 'crt.id', '=', 'cel.relationship_type_id')
            ->where('cel.entity_type', 'alliance')
            ->where('cel.entity_id', $aid)
            ->where('cel.is_active', 1)
            ->select('cb.display_name AS bloc', 'crt.display_name AS role')
            ->first();

        // Active pilots: any character that appeared as an attacker in
        // this alliance over the last 90d (via alliance_id on the ka
        // row). Cheaper + more current than corp-history traversal.
        $pilotIds = DB::select(<<<'SQL'
            SELECT DISTINCT ka.character_id AS cid
              FROM killmail_attackers ka
              JOIN killmails k ON k.killmail_id = ka.killmail_id
             WHERE ka.alliance_id = ?
               AND ka.character_id IS NOT NULL
               AND k.killed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
        SQL, [$aid]);
        $pilotIds = array_map(fn ($r) => (int) $r->cid, $pilotIds);
        $pilotCount = count($pilotIds);

        // Headline stats: kills scored / losses taken / ISK totals / top hulls.
        $headline = $this->headlineStats($aid);

        // Role layers from ci_character_features_rolling. Joining by
        // character_id filtered to pilotIds keeps it cheap.
        $layers = $this->roleLayers($aid, $pilotIds);

        // Corps: distinct corp_id rollup with pilot counts.
        $corps = $this->corpBreakdown($aid);

        // Hour histogram (UTC) from alliance-wide killmail activity.
        $hourHistogram = $this->hourHistogram($aid);

        // FC clusters: each top FC + their top 5 same-side crew from Neo4j.
        $fcClusters = $this->fcClusters($layers['fc'] ?? []);

        return [
            'search' => $this->search,
            'alliance' => [
                'id' => $aid,
                'name' => $allianceName ?? "Alliance #{$aid}",
                'bloc' => $blocRow->bloc ?? null,
                'role' => $blocRow->role ?? null,
                'pilot_count' => $pilotCount,
            ],
            'headline' => $headline,
            'layers' => $layers,
            'corps' => $corps,
            'hour_histogram' => $hourHistogram,
            'fc_clusters' => $fcClusters,
            'suggestions' => $suggestions,
        ];
    }

    /** @return array<string, mixed> */
    private function headlineStats(int $aid): array
    {
        $kills = DB::table('killmail_attackers AS ka')
            ->join('killmails AS k', 'k.killmail_id', '=', 'ka.killmail_id')
            ->where('ka.alliance_id', $aid)
            ->where('k.killed_at', '>=', now()->subDays(90))
            ->distinct('ka.killmail_id')
            ->count('ka.killmail_id');
        $losses = DB::table('killmails')
            ->where('victim_alliance_id', $aid)
            ->where('killed_at', '>=', now()->subDays(90))
            ->count();
        $iskDestroyed = (float) DB::table('killmails AS k')
            ->whereIn('k.killmail_id', function ($q) use ($aid): void {
                $q->select('killmail_id')->from('killmail_attackers')
                    ->where('alliance_id', $aid);
            })
            ->where('k.killed_at', '>=', now()->subDays(90))
            ->sum('k.total_value');
        $iskLost = (float) DB::table('killmails')
            ->where('victim_alliance_id', $aid)
            ->where('killed_at', '>=', now()->subDays(90))
            ->sum('total_value');
        return [
            'kills' => $kills,
            'losses' => $losses,
            'isk_destroyed' => $iskDestroyed,
            'isk_lost' => $iskLost,
        ];
    }

    /**
     * @param  list<int>  $pilotIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function roleLayers(int $aid, array $pilotIds): array
    {
        if ($pilotIds === []) return [];
        $roleKeys = ['fc', 'command', 'logi', 'tackle', 'bomber', 'mainline_dps'];
        $out = array_fill_keys($roleKeys, []);
        foreach ($roleKeys as $role) {
            $col = "role_{$role}_pct";
            $rows = DB::table('ci_character_features_rolling AS f')
                ->leftJoin('esi_entity_names AS en', function ($j): void {
                    $j->on('en.entity_id', '=', 'f.character_id')->where('en.category', 'character');
                })
                ->whereIn('f.character_id', $pilotIds)
                ->where('f.has_sufficient_history', 1)
                ->where($col, '>=', 0.10)
                ->orderByRaw("f.{$col} * f.battles DESC")
                ->limit(10)
                ->select(
                    'f.character_id', 'en.name', "f.{$col} AS role_pct",
                    'f.battles', 'f.killmails_attacker', 'f.avg_damage_share',
                )
                ->get();
            $out[$role] = $rows->map(fn ($r) => [
                'character_id' => (int) $r->character_id,
                'name' => $r->name ? (string) $r->name : "Pilot #{$r->character_id}",
                'role_pct' => (float) $r->role_pct,
                'battles' => (int) $r->battles,
                'killmails_attacker' => (int) $r->killmails_attacker,
                'avg_damage_share' => (float) $r->avg_damage_share,
            ])->all();
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function corpBreakdown(int $aid): array
    {
        return DB::select(<<<'SQL'
            SELECT ka.corporation_id,
                   en.name,
                   COUNT(DISTINCT ka.character_id) AS pilots,
                   COUNT(DISTINCT ka.killmail_id) AS kms
              FROM killmail_attackers ka
              JOIN killmails k ON k.killmail_id = ka.killmail_id
              LEFT JOIN esi_entity_names en ON en.entity_id = ka.corporation_id AND en.category = 'corporation'
             WHERE ka.alliance_id = ?
               AND ka.corporation_id IS NOT NULL
               AND k.killed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
             GROUP BY ka.corporation_id, en.name
             ORDER BY pilots DESC
             LIMIT 12
        SQL, [$aid]);
    }

    /** @return list<int> 24 bins */
    private function hourHistogram(int $aid): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT HOUR(k.killed_at) AS h, COUNT(DISTINCT ka.killmail_id) AS n
              FROM killmail_attackers ka
              JOIN killmails k ON k.killmail_id = ka.killmail_id
             WHERE ka.alliance_id = ?
               AND k.killed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
             GROUP BY HOUR(k.killed_at)
        SQL, [$aid]);
        $h = array_fill(0, 24, 0);
        foreach ($rows as $r) $h[(int) $r->h] = (int) $r->n;
        return $h;
    }

    /**
     * @param  list<array<string, mixed>>  $fcList
     * @return list<array<string, mixed>>
     */
    private function fcClusters(array $fcList): array
    {
        if ($fcList === []) return [];
        $svc = app(\App\Domains\CounterIntel\Services\CharacterGraphInsightService::class);
        $clusters = [];
        $topFcs = array_slice($fcList, 0, 6);
        foreach ($topFcs as $fc) {
            $crew = $svc->flightCrew((int) $fc['character_id'], 5);
            $clusters[] = [
                'fc' => $fc,
                'crew' => $crew,
            ];
        }
        return $clusters;
    }
}

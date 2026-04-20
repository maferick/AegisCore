<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Domains\Markets\Services\MarketHubAccessPolicy;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/my-doctrines/market — doctrine-driven market lookup.
 *
 * Model:
 *  - Start from the primary doctrines surfaced on /portal/my-doctrines
 *    (already scoped to the viewer's corp / alliance / bloc, already
 *    capped at ≤ 2 variants per hull).
 *  - Each doctrine carries observation_count = losses in the last
 *    window_days (default 30). Assume every loss destroys every
 *    fitted module (conservative, matches kill-feed reality where
 *    drop chance is 50/50 and we can't distinguish at scope).
 *  - Weekly burn per module = Σ across contributing doctrines of
 *    (doctrine.scope_n × 7 / window_days × module.quantity).
 *  - Stock = sum of volume_remain on sell orders in the viewer's
 *    selected private market hub(s), keyed on canonical_type_id so
 *    meta-variant swaps don't fragment supply.
 *  - Runway days = stock / daily burn.
 *  - Deficit to reach target coverage (default 14 d) =
 *        max(0, target_days × daily_burn − stock).
 *
 *  Output columns:
 *    module / scope hit (corp|alli|bloc) / weekly burn / stock /
 *    runway days / deficit or surplus / est. buy ISK.
 */
class DoctrineMarket extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'Doctrine Market';

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?int $navigationSort = 71;

    protected static ?string $title = 'Doctrine Market';

    protected static ?string $slug = 'my-doctrines/market';

    protected string $view = 'filament.portal.pages.doctrine-market';

    private const WINDOW_DAYS = 30;

    private const TARGET_COVERAGE_DAYS = 60;

    public ?int $hubId = null;

    public int $targetDays = self::TARGET_COVERAGE_DAYS;

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check();
    }

    public function mount(MarketHubAccessPolicy $policy): void
    {
        $user = Auth::user();
        if ($user === null) return;
        // hub=0 (or "all") → aggregate across every hub the viewer
        // can see. Any positive integer → single hub.
        $q = (string) request()->query('hub', '');
        if ($q === 'all' || $q === '0') {
            $this->hubId = 0;
        } elseif ($q !== '' && ctype_digit($q)) {
            $this->hubId = (int) $q;
        } else {
            $this->hubId = $user->default_private_market_hub_id
                ?? $policy->visibleHubsFor($user)->value('id');
            $this->hubId = $this->hubId ? (int) $this->hubId : null;
        }
        $t = (int) request()->query('days', self::TARGET_COVERAGE_DAYS);
        $this->targetDays = max(3, min($t, 120));
    }

    /** @return array<string,mixed> */
    public function getViewData(): array
    {
        $user = Auth::user();
        $policy = app(MarketHubAccessPolicy::class);
        if ($user === null) {
            return $this->empty();
        }
        $char = $user->characters()->first();
        if ($char === null) {
            return $this->empty();
        }

        $corpId = (int) ($char->corporation_id ?? 0);
        $allianceId = (int) ($char->alliance_id ?? 0);
        $blocId = null;
        if ($allianceId > 0) {
            $blocId = DB::table('coalition_entity_labels')
                ->where('entity_type', 'alliance')->where('entity_id', $allianceId)
                ->where('is_active', 1)->value('bloc_id');
            $blocId = $blocId ? (int) $blocId : null;
        }

        $visibleHubs = $policy->visibleHubsFor($user)
            ->orderByDesc('is_public_reference')
            ->orderBy('structure_name')
            ->get(['id', 'structure_name', 'location_id', 'region_id', 'is_public_reference']);

        if ($visibleHubs->isEmpty()) {
            return [
                'corp_id' => $corpId ?: null, 'alliance_id' => $allianceId ?: null, 'bloc_id' => $blocId,
                'hubs' => collect(), 'hub_id' => null, 'target_days' => $this->targetDays,
                'rows' => [], 'totals' => $this->emptyTotals(), 'no_hub' => true,
            ];
        }

        // Aggregate mode (hub=0 / "all") scans every visible hub and
        // sums stock / picks the cheapest price point across them.
        // Single-hub mode (hub=<id>) keeps the old one-location query.
        if ($this->hubId === 0) {
            $locationIds = $visibleHubs->pluck('location_id')->map(fn ($v) => (int) $v)->all();
            $hubId = 0;
            $hubName = 'All hubs (' . count($locationIds) . ')';
        } else {
            $hubId = $this->hubId && $visibleHubs->pluck('id')->contains($this->hubId)
                ? $this->hubId
                : (int) $visibleHubs->first()->id;
            $hub = $visibleHubs->firstWhere('id', $hubId);
            $locationIds = [(int) $hub->location_id];
            $hubName = (string) $hub->structure_name;
        }

        // 1. Pull primary doctrines across all three scope tiers.
        //    Collapse to unique doctrine_id so a fit adopted by both
        //    corp and alliance doesn't double-count loss rate.
        $doctrines = $this->primaryDoctrines($corpId, $allianceId, $blocId);

        // 2. Per-module weekly burn.
        //    canonical_type_id + flag_category is the burn key; meta
        //    variants collapse to the same canonical stock bucket.
        [$moduleBurn, $hullBurn] = $this->computeBurn($doctrines);

        // 3. Stock from market_orders across the selected hub(s).
        $typeIds = array_unique(array_merge(array_keys($moduleBurn), array_keys($hullBurn)));
        $stockByType = $this->stockAtHubs($typeIds, $locationIds);

        // 4. Price (median of lowest 5 sell orders per type) aggregated
        //    across locations — cheapest across all hubs wins.
        $priceByType = $this->priceAtHubs($typeIds, $locationIds);

        // 5. Build rows.
        $rows = [];
        $targetDays = $this->targetDays;
        foreach ($moduleBurn as $typeId => $meta) {
            $rows[] = $this->buildRow($typeId, $meta, $stockByType, $priceByType, $targetDays, 'module');
        }
        foreach ($hullBurn as $typeId => $meta) {
            $rows[] = $this->buildRow($typeId, $meta, $stockByType, $priceByType, $targetDays, 'hull');
        }
        usort($rows, function ($a, $b) {
            // Hulls first, then deficit desc, then weekly burn desc.
            if ($a['kind'] !== $b['kind']) return $a['kind'] === 'hull' ? -1 : 1;
            return ($b['deficit_qty'] <=> $a['deficit_qty']) ?: ($b['weekly_burn'] <=> $a['weekly_burn']);
        });

        $totals = $this->totals($rows);

        return [
            'corp_id' => $corpId ?: null, 'alliance_id' => $allianceId ?: null, 'bloc_id' => $blocId,
            'hubs' => $visibleHubs, 'hub_id' => $hubId, 'hub_name' => $hubName,
            'target_days' => $targetDays, 'window_days' => self::WINDOW_DAYS,
            'rows' => $rows, 'totals' => $totals, 'doctrine_count' => count($doctrines),
            'no_hub' => false,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function primaryDoctrines(int $corpId, int $allianceId, ?int $blocId): array
    {
        $rows = [];
        if ($corpId > 0)     $rows = array_merge($rows, $this->doctrinesFromScope('auto_doctrine_adopters', 'corporation_id', $corpId, 'corp'));
        if ($allianceId > 0) $rows = array_merge($rows, $this->doctrinesFromScope('auto_doctrine_alliance_adopters', 'alliance_id', $allianceId, 'alliance'));
        if ($blocId)         $rows = array_merge($rows, $this->doctrinesFromScope('auto_doctrine_bloc_adopters', 'bloc_id', $blocId, 'bloc'));

        // Dedupe by doctrine_id, keep tightest scope (corp > alliance > bloc) for label.
        $scopeRank = ['corp' => 3, 'alliance' => 2, 'bloc' => 1];
        $byId = [];
        foreach ($rows as $r) {
            $id = $r['id'];
            if (! isset($byId[$id]) || $scopeRank[$r['scope']] > $scopeRank[$byId[$id]['scope']]) {
                $byId[$id] = $r;
            }
        }
        return array_values($byId);
    }

    /** @return list<array<string,mixed>> */
    private function doctrinesFromScope(string $table, string $col, int $scopeId, string $scopeLabel): array
    {
        // Mirror MyDoctrines::classifyBuckets filter: only the rank-0
        // (and qualifying rank-1) variants per hull.
        $rows = DB::table("{$table} AS a")
            ->join('auto_doctrines AS d', 'd.id', '=', 'a.doctrine_id')
            ->where('d.is_active', 1)
            ->where("a.{$col}", $scopeId)
            ->select(
                'd.id', 'd.hull_type_id', 'd.role_key', 'd.canonical_name',
                'd.observation_count AS global_n',
                'a.observation_count AS scope_n'
            )
            ->get();
        if ($rows->isEmpty()) return [];

        // Same classifier logic as MyDoctrines (top-2 per hull with
        // guardrails): rank-0 always in if meets floor; rank-1 only
        // if share >= 0.2 and scope_n >= 0.4 × rank-0.
        $leaderByRole = [];
        foreach ($rows as $r) {
            $leaderByRole[$r->role_key] = max($leaderByRole[$r->role_key] ?? 0, (int) $r->scope_n);
        }
        $floorByRole = [];
        foreach ($leaderByRole as $role => $leader) {
            $floorByRole[$role] = max(10, (int) ceil($leader * 0.10));
        }

        $hullGroups = [];
        foreach ($rows as $i => $r) {
            $hullGroups[$r->role_key.'|'.$r->hull_type_id][] = $i;
        }
        $kept = [];
        foreach ($hullGroups as $idxs) {
            usort($idxs, fn ($a, $b) => (int) $rows[$b]->scope_n <=> (int) $rows[$a]->scope_n);
            $topScope = (int) $rows[$idxs[0]]->scope_n;
            foreach ($idxs as $rank => $i) {
                $r = $rows[$i];
                $floor = $floorByRole[$r->role_key] ?? 10;
                $meets = (int) $r->scope_n >= $floor;
                if ($rank === 0) {
                    $ok = $meets;
                } elseif ($rank === 1) {
                    $share = $r->global_n > 0 ? (int) $r->scope_n / (int) $r->global_n : 0.0;
                    $ok = $meets && $share >= 0.20 && $topScope > 0 && (int) $r->scope_n >= 0.40 * $topScope;
                } else {
                    $ok = false;
                }
                if ($ok) {
                    $kept[] = [
                        'id' => (int) $r->id,
                        'hull_type_id' => (int) $r->hull_type_id,
                        'role_key' => (string) $r->role_key,
                        'canonical_name' => (string) $r->canonical_name,
                        'scope_n' => (int) $r->scope_n,
                        'scope' => $scopeLabel,
                    ];
                }
            }
        }
        return $kept;
    }

    /**
     * @param list<array<string,mixed>> $doctrines
     * @return array{0: array<int,array<string,mixed>>, 1: array<int,array<string,mixed>>}
     *   [moduleBurn keyed by canonical_type_id, hullBurn keyed by hull_type_id]
     */
    private function computeBurn(array $doctrines): array
    {
        if ($doctrines === []) return [[], []];
        $ids = array_column($doctrines, 'id');
        $modulesByDoctrine = DB::table('auto_doctrine_modules AS m')
            ->leftJoin('ref_item_types AS rit', 'rit.id', '=', 'm.canonical_type_id')
            ->whereIn('m.doctrine_id', $ids)
            ->select('m.doctrine_id', 'm.canonical_type_id', 'm.quantity', 'm.flag_category', 'rit.name AS type_name')
            ->get()
            ->groupBy('doctrine_id');

        $moduleBurn = [];
        $hullBurn = [];
        $windowDays = self::WINDOW_DAYS;

        foreach ($doctrines as $d) {
            $losses = (int) $d['scope_n'];           // losses in window
            $weeklyHulls = $losses * 7.0 / $windowDays;

            // Hull itself is also burned at the same rate.
            $hid = (int) $d['hull_type_id'];
            $hullRow = $hullBurn[$hid] ?? ['type_id' => $hid, 'name' => null, 'weekly_burn' => 0.0, 'contributors' => []];
            $hullRow['weekly_burn'] += $weeklyHulls;
            $hullRow['contributors'][] = ['doctrine' => $d['canonical_name'], 'scope' => $d['scope'], 'scope_n' => $losses, 'qty_per_fit' => 1];
            $hullBurn[$hid] = $hullRow;

            $mods = $modulesByDoctrine->get($d['id']) ?? collect();
            foreach ($mods as $m) {
                $typeId = (int) $m->canonical_type_id;
                if ($typeId <= 0) continue;
                $qty = max(1, (int) $m->quantity);
                $weekly = $weeklyHulls * $qty;
                $row = $moduleBurn[$typeId] ?? ['type_id' => $typeId, 'name' => $m->type_name, 'slot' => $m->flag_category, 'weekly_burn' => 0.0, 'contributors' => []];
                $row['weekly_burn'] += $weekly;
                if (! $row['name'] && $m->type_name) $row['name'] = $m->type_name;
                $row['contributors'][] = ['doctrine' => $d['canonical_name'], 'scope' => $d['scope'], 'scope_n' => $losses, 'qty_per_fit' => $qty];
                $moduleBurn[$typeId] = $row;
            }
        }

        // Fill hull names.
        if ($hullBurn !== []) {
            $names = DB::table('ref_item_types')->whereIn('id', array_keys($hullBurn))->pluck('name', 'id');
            foreach ($hullBurn as $hid => $r) {
                $hullBurn[$hid]['name'] = $names[$hid] ?? ('Hull #' . $hid);
            }
        }

        return [$moduleBurn, $hullBurn];
    }

    /**
     * @param list<int> $typeIds
     * @param list<int> $locationIds
     * @return array<int, array{stock:int}>
     */
    private function stockAtHubs(array $typeIds, array $locationIds): array
    {
        if ($typeIds === [] || $locationIds === []) return [];
        // Sum recent sell-side volume_remain across all selected
        // locations. Per-location stock double-counts nothing because
        // hubs are disjoint structure ids.
        $rows = DB::table('market_orders')
            ->whereIn('location_id', $locationIds)
            ->where('is_buy', 0)
            ->whereIn('type_id', $typeIds)
            ->where('observed_at', '>=', now()->subHours(2))
            ->select('type_id', DB::raw('SUM(volume_remain) AS qty'))
            ->groupBy('type_id')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->type_id] = ['stock' => (int) $r->qty];
        }
        return $out;
    }

    /**
     * Median of the 5 cheapest sell-order prices per type, aggregated
     * across all selected hubs. Single-hub mode collapses to the old
     * per-hub query behaviour; multi-hub mode takes the cheapest 5
     * globally across locations — good proxy for "the floor you'd pay
     * to fill the deficit anywhere".
     *
     * @param list<int> $typeIds
     * @param list<int> $locationIds
     * @return array<int, float>
     */
    private function priceAtHubs(array $typeIds, array $locationIds): array
    {
        if ($typeIds === [] || $locationIds === []) return [];
        $typePh = implode(',', array_fill(0, count($typeIds), '?'));
        $locPh = implode(',', array_fill(0, count($locationIds), '?'));
        $rows = DB::select("
            SELECT t.type_id, t.price
              FROM (
                SELECT type_id, price,
                       ROW_NUMBER() OVER (PARTITION BY type_id ORDER BY price ASC) AS rn
                  FROM market_orders
                 WHERE location_id IN ({$locPh})
                   AND is_buy = 0
                   AND type_id IN ({$typePh})
                   AND observed_at >= ?
                   AND volume_remain > 0
              ) t
             WHERE t.rn <= 5
        ", array_merge($locationIds, $typeIds, [now()->subHours(2)]));
        $byType = [];
        foreach ($rows as $r) $byType[(int) $r->type_id][] = (float) $r->price;
        $out = [];
        foreach ($byType as $tid => $prices) {
            sort($prices);
            $mid = intdiv(count($prices), 2);
            $out[$tid] = count($prices) % 2 ? $prices[$mid] : ($prices[$mid - 1] + $prices[$mid]) / 2;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<int,array{stock:int}> $stockByType
     * @param array<int,float> $priceByType
     */
    private function buildRow(int $typeId, array $meta, array $stockByType, array $priceByType, int $targetDays, string $kind): array
    {
        $weekly = (float) $meta['weekly_burn'];
        $daily = $weekly / 7;
        $stock = (int) ($stockByType[$typeId]['stock'] ?? 0);
        $runwayDays = $daily > 0 ? round($stock / $daily, 1) : null;
        $target = (int) ceil($daily * $targetDays);
        $deficit = max(0, $target - $stock);
        $surplus = max(0, $stock - $target);
        $price = $priceByType[$typeId] ?? null;
        $buyIsk = $price !== null ? $deficit * $price : null;
        return [
            'type_id' => $typeId,
            'name' => $meta['name'] ?? ('type ' . $typeId),
            'slot' => $meta['slot'] ?? null,
            'kind' => $kind,
            'weekly_burn' => $weekly,
            'daily_burn' => $daily,
            'stock' => $stock,
            'runway_days' => $runwayDays,
            'target_qty' => $target,
            'deficit_qty' => $deficit,
            'surplus_qty' => $surplus,
            'price' => $price,
            'buy_isk' => $buyIsk,
            'contributors' => $meta['contributors'] ?? [],
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function totals(array $rows): array
    {
        $deficitIsk = 0.0;
        $stockIsk = 0.0;
        $deficitLines = 0;
        foreach ($rows as $r) {
            if ($r['buy_isk'] !== null) $deficitIsk += $r['buy_isk'];
            if ($r['price'] !== null)   $stockIsk   += $r['stock'] * $r['price'];
            if ($r['deficit_qty'] > 0)  $deficitLines++;
        }
        return [
            'stock_isk' => $stockIsk,
            'deficit_isk' => $deficitIsk,
            'deficit_lines' => $deficitLines,
            'lines' => count($rows),
        ];
    }

    /** @return array<string,mixed> */
    private function empty(): array
    {
        return [
            'corp_id' => null, 'alliance_id' => null, 'bloc_id' => null,
            'hubs' => collect(), 'hub_id' => null, 'target_days' => $this->targetDays,
            'rows' => [], 'totals' => $this->emptyTotals(), 'no_hub' => true,
            'doctrine_count' => 0, 'window_days' => self::WINDOW_DAYS,
        ];
    }

    private function emptyTotals(): array
    {
        return ['stock_isk' => 0.0, 'deficit_isk' => 0.0, 'deficit_lines' => 0, 'lines' => 0];
    }
}

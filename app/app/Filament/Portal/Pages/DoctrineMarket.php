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

    /** Hub used for stock counts (where we keep inventory). */
    public ?int $hubId = null;

    /** Hub used for price reference (where you'd buy to refill). */
    public ?int $priceHubId = null;

    public int $targetDays = self::TARGET_COVERAGE_DAYS;

    /** all = every line; deficit = only rows where target > stock. */
    public string $viewMode = 'deficit';

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check();
    }

    public function mount(MarketHubAccessPolicy $policy): void
    {
        $user = Auth::user();
        if ($user === null) return;
        // Stock hub: hub=0 / "all" → aggregate every visible hub;
        // positive integer → specific hub. Default: user's private
        // staging hub (what they keep inventory in), fallback to
        // first visible hub.
        $parse = function (string $q, $default): ?int {
            if ($q === 'all' || $q === '0') return 0;
            if ($q !== '' && ctype_digit($q)) return (int) $q;
            return $default ? (int) $default : null;
        };

        // Default stock hub preference:
        //   1. User's saved default (portal/account/market-hubs)
        //   2. First visible hub flagged is_public_reference=0
        //   3. First visible hub NOT at Jita location_id 60003760
        //      (catches orgs that marked their staging is_public=1)
        //   4. First visible hub
        $defaultStock = $user->default_private_market_hub_id
            ?? $policy->visibleHubsFor($user)->where('is_public_reference', 0)->value('id')
            ?? $policy->visibleHubsFor($user)->where('location_id', '!=', 60003760)->value('id')
            ?? $policy->visibleHubsFor($user)->value('id');
        $this->hubId = $parse((string) request()->query('hub', ''), $defaultStock);

        // Price hub: where we'd buy the deficit. Default: first
        // public reference (Jita) so prices match real market. Falls
        // back to the stock hub when no public ref exists.
        $defaultPrice = $policy->visibleHubsFor($user)->where('is_public_reference', 1)->value('id')
            ?? $defaultStock;
        $this->priceHubId = $parse((string) request()->query('price_hub', ''), $defaultPrice);
        $t = (int) request()->query('days', self::TARGET_COVERAGE_DAYS);
        $this->targetDays = max(3, min($t, 120));

        $view = (string) request()->query('view', 'deficit');
        $this->viewMode = in_array($view, ['all', 'deficit'], true) ? $view : 'deficit';
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

        // Resolve stock hub (inventory lookup) + price hub (deficit
        // pricing) independently. Both accept "all" to aggregate.
        $resolveHub = function (?int $id) use ($visibleHubs): array {
            if ($id === 0) {
                return [
                    'id' => 0,
                    'locationIds' => $visibleHubs->pluck('location_id')->map(fn ($v) => (int) $v)->all(),
                    'name' => 'All hubs (' . $visibleHubs->count() . ')',
                ];
            }
            $ok = $id && $visibleHubs->pluck('id')->contains($id);
            $row = $ok ? $visibleHubs->firstWhere('id', $id) : $visibleHubs->first();
            $name = $row->structure_name ?: ('Structure ' . substr((string) $row->location_id, -8) . ' (private)');
            return [
                'id' => (int) $row->id,
                'locationIds' => [(int) $row->location_id],
                'name' => (string) $name,
            ];
        };
        $stock = $resolveHub($this->hubId);
        $price = $resolveHub($this->priceHubId);
        $hubId = $stock['id'];
        $hubName = $stock['name'];
        $priceHubId = $price['id'];
        $priceHubName = $price['name'];

        // 1. Pull primary doctrines across all three scope tiers.
        //    Collapse to unique doctrine_id so a fit adopted by both
        //    corp and alliance doesn't double-count loss rate.
        $doctrines = $this->primaryDoctrines($corpId, $allianceId, $blocId);

        // 2. Per-module weekly burn.
        //    canonical_type_id + flag_category is the burn key; meta
        //    variants collapse to the same canonical stock bucket.
        [$moduleBurn, $hullBurn] = $this->computeBurn(
            $doctrines,
            ['corp' => $corpId ?: null, 'alliance' => $allianceId ?: null, 'bloc' => $blocId],
        );

        // 3. Stock from market_orders across the selected stock hub(s).
        $typeIds = array_unique(array_merge(array_keys($moduleBurn), array_keys($hullBurn)));
        $stockByType = $this->stockAtHubs($typeIds, $stock['locationIds']);

        // 4. Price from the selected price hub(s). Usually a public
        //    reference hub (Jita) even when stock lives in a private
        //    staging hub — "deficit ISK" then matches what you'd
        //    actually pay to refill on the open market.
        $priceByType = $this->priceAtHubs($typeIds, $price['locationIds']);

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

        // Totals are always computed over the full row set so the
        // deficit-toggle doesn't hide the KPI tiles.
        $totals = $this->totals($rows);

        $displayRows = $this->viewMode === 'deficit'
            ? array_values(array_filter($rows, fn ($r) => ($r['deficit_qty'] ?? 0) > 0))
            : $rows;

        return [
            'corp_id' => $corpId ?: null, 'alliance_id' => $allianceId ?: null, 'bloc_id' => $blocId,
            'hubs' => $visibleHubs,
            'hub_id' => $hubId, 'hub_name' => $hubName,
            'price_hub_id' => $priceHubId, 'price_hub_name' => $priceHubName,
            'target_days' => $targetDays, 'window_days' => self::WINDOW_DAYS,
            'rows' => $displayRows, 'totals' => $totals, 'doctrine_count' => count($doctrines),
            'view_mode' => $this->viewMode,
            'hidden_count' => count($rows) - count($displayRows),
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
    /**
     * Hull burn uses *every* active doctrine for the hull at the
     * viewer's scope (not just the top-1/2 primary variants we
     * display) so replenishment matches reality. Module burn stays
     * on the primary doctrines only — otherwise the shopping list
     * picks up tail-variant charges that bury the signal.
     *
     * @param list<array<string,mixed>> $doctrines  Primary variants only.
     * @param array{corp:?int,alliance:?int,bloc:?int} $scopeIds
     */
    private function computeBurn(array $doctrines, array $scopeIds): array
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

        // Per-hull primary weekly burn (used for module burn — we only
        // want the primary variant's modules on the shopping list).
        $primaryHullWeeklyBurn = [];
        foreach ($doctrines as $d) {
            $losses = (int) $d['scope_n'];
            $weeklyHulls = $losses * 7.0 / $windowDays;
            $hid = (int) $d['hull_type_id'];
            $primaryHullWeeklyBurn[$hid] = ($primaryHullWeeklyBurn[$hid] ?? 0.0) + $weeklyHulls;

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

        // Hull burn — widen to ALL active doctrines for the viewer's
        // scope (corp → alliance → bloc precedence, matches the
        // adopter table the primary doctrines were pulled from).
        $hullIds = array_keys($primaryHullWeeklyBurn);
        $hullTotals = $this->allDoctrineHullTotals($hullIds, $scopeIds);

        foreach ($hullIds as $hid) {
            $primaryWeekly = (float) ($primaryHullWeeklyBurn[$hid] ?? 0);
            $totalLosses = (int) ($hullTotals[$hid] ?? 0);
            $totalWeekly = $totalLosses > 0
                ? $totalLosses * 7.0 / $windowDays
                : $primaryWeekly;           // fallback
            $tailWeekly = max(0.0, $totalWeekly - $primaryWeekly);
            $hullBurn[$hid] = [
                'type_id' => $hid,
                'name' => null,
                'weekly_burn' => $totalWeekly,
                'primary_weekly_burn' => $primaryWeekly,
                'tail_weekly_burn' => $tailWeekly,
                'total_losses_30d' => $totalLosses,
                'contributors' => [],
            ];
        }

        if ($hullBurn !== []) {
            $names = DB::table('ref_item_types')->whereIn('id', array_keys($hullBurn))->pluck('name', 'id');
            foreach ($hullBurn as $hid => $r) {
                $hullBurn[$hid]['name'] = $names[$hid] ?? ('Hull #' . $hid);
            }
        }

        return [$moduleBurn, $hullBurn];
    }

    /**
     * True 30-day raw loss count per hull, at the widest available
     * scope (bloc > alliance > corp). Queries killmails directly so
     * we don't inherit the doctrine pipeline's fit-attribution
     * double-counting (one kill can match multiple doctrine variants,
     * inflating adopter sums 2× or more).
     *
     * @param list<int> $hullIds
     * @param array{corp:?int,alliance:?int,bloc:?int} $scopeIds
     * @return array<int,int>
     */
    private function allDoctrineHullTotals(array $hullIds, array $scopeIds): array
    {
        if ($hullIds === []) return [];
        $out = [];
        foreach ($hullIds as $hid) $out[$hid] = 0;

        $since = now()->subDays(self::WINDOW_DAYS);
        $ph = implode(',', array_fill(0, count($hullIds), '?'));

        // Widest-available scope wins: bloc → alliance → corp. Use
        // raw killmail counts scoped to the viewer's membership.
        if (($scopeIds['bloc'] ?? null) !== null) {
            $rows = DB::select(<<<SQL
                SELECT k.victim_ship_type_id AS hid, COUNT(*) AS n
                  FROM killmails k
                  JOIN coalition_entity_labels cel
                    ON cel.entity_type = 'alliance'
                   AND cel.entity_id = k.victim_alliance_id
                   AND cel.is_active = 1
                 WHERE cel.bloc_id = ?
                   AND k.victim_ship_type_id IN ($ph)
                   AND k.killed_at >= ?
                 GROUP BY k.victim_ship_type_id
            SQL, array_merge([$scopeIds['bloc']], $hullIds, [$since]));
            foreach ($rows as $r) $out[(int) $r->hid] = (int) $r->n;
        } elseif (($scopeIds['alliance'] ?? null) !== null) {
            $rows = DB::select(<<<SQL
                SELECT victim_ship_type_id AS hid, COUNT(*) AS n
                  FROM killmails
                 WHERE victim_alliance_id = ?
                   AND victim_ship_type_id IN ($ph)
                   AND killed_at >= ?
                 GROUP BY victim_ship_type_id
            SQL, array_merge([$scopeIds['alliance']], $hullIds, [$since]));
            foreach ($rows as $r) $out[(int) $r->hid] = (int) $r->n;
        } elseif (($scopeIds['corp'] ?? null) !== null) {
            $rows = DB::select(<<<SQL
                SELECT victim_ship_type_id AS hid, COUNT(*) AS n
                  FROM killmails
                 WHERE victim_corporation_id = ?
                   AND victim_ship_type_id IN ($ph)
                   AND killed_at >= ?
                 GROUP BY victim_ship_type_id
            SQL, array_merge([$scopeIds['corp']], $hullIds, [$since]));
            foreach ($rows as $r) $out[(int) $r->hid] = (int) $r->n;
        }

        return $out;
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
            'primary_weekly_burn' => $meta['primary_weekly_burn'] ?? null,
            'tail_weekly_burn' => $meta['tail_weekly_burn'] ?? null,
            'total_losses_30d' => $meta['total_losses_30d'] ?? null,
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

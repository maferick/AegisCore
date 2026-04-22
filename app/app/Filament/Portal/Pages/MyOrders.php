<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Domains\UsersCharacters\Services\PersonalMarketOrdersFetcher;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/my-orders — personal market orders (open + 90d history)
 * across main + all linked alts. Rolled up via user_id so a single
 * page shows every order under the account regardless of which
 * character placed it.
 *
 * Filters:
 *   ?state=open   (default) — currently listed orders only
 *   ?state=closed           — history (expired / cancelled / closed)
 *   ?state=all              — everything we've observed
 *   ?character=<id>         — restrict to one linked character
 */
class MyOrders extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'My Orders';

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?int $navigationSort = 72;

    protected static ?string $title = 'My Market Orders';

    protected static ?string $slug = 'my-orders';

    protected string $view = 'filament.portal.pages.my-orders';

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check();
    }

    /** @return array<string,mixed> */
    public function getViewData(): array
    {
        $user = Auth::user();
        if ($user === null) return ['no_user' => true];

        $characters = $user->characters()->orderBy('id')->get(['id', 'character_id', 'name']);
        if ($characters->isEmpty()) {
            return ['no_characters' => true];
        }
        $mainCharId = $user->main_character_id;

        $stateMode = (string) request()->query('state', 'open');
        if (! in_array($stateMode, ['open', 'closed', 'all'], true)) $stateMode = 'open';

        $characterFilter = (int) request()->query('character', 0);
        $characterIds = $characters->pluck('character_id')->map(fn ($v) => (int) $v)->all();

        $q = DB::table('personal_market_orders AS o')
            ->leftJoin('ref_item_types AS rit', 'rit.id', '=', 'o.type_id')
            ->leftJoin('ref_npc_stations AS rns', 'rns.id', '=', 'o.location_id')
            ->whereIn('o.character_id', $characterIds)
            ->select(
                'o.*',
                'rit.name AS type_name',
                'rns.id AS station_id',
            );
        if ($stateMode === 'open') {
            $q->where('o.state', 'open');
        } elseif ($stateMode === 'closed') {
            $q->whereIn('o.state', ['expired', 'cancelled', 'closed']);
        }
        if ($characterFilter > 0 && in_array($characterFilter, $characterIds, true)) {
            $q->where('o.character_id', $characterFilter);
        }
        $orders = $q->orderByDesc('o.issued')->limit(1000)->get();

        // Resolve location display names: NPC stations + known
        // market_hubs; unknown structures surface as the raw id.
        $locationIds = $orders->pluck('location_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $locations = [];
        if ($locationIds) {
            $stations = DB::table('ref_npc_stations')
                ->whereIn('ref_npc_stations.id', $locationIds)
                ->leftJoin('ref_solar_systems AS s', 's.id', '=', 'ref_npc_stations.solar_system_id')
                ->select('ref_npc_stations.id AS station_id', 's.name AS system_name')
                ->get();
            foreach ($stations as $s) {
                $locations[(int) $s->station_id] = ['name' => $s->system_name ? ($s->system_name . ' station') : "Station #{$s->station_id}"];
            }
            $hubs = DB::table('market_hubs')
                ->whereIn('location_id', $locationIds)
                ->select('location_id', 'structure_name')
                ->get();
            foreach ($hubs as $h) {
                $locations[(int) $h->location_id] = ['name' => $h->structure_name ?? ("Structure " . substr((string) $h->location_id, -8))];
            }
        }

        // Character display metadata keyed by character_id.
        $charMeta = [];
        foreach ($characters as $c) {
            $charMeta[(int) $c->character_id] = [
                'id' => (int) $c->id,
                'character_id' => (int) $c->character_id,
                'name' => $c->name,
                'is_main' => $mainCharId !== null && (int) $c->id === (int) $mainCharId,
            ];
        }

        // Totals for KPI tiles (always over the filtered slice).
        $totalsOpen = DB::table('personal_market_orders')
            ->whereIn('character_id', $characterIds)
            ->where('state', 'open')
            ->selectRaw('SUM(CASE WHEN is_buy=1 THEN price*volume_remain ELSE 0 END) AS buy_isk,
                         SUM(CASE WHEN is_buy=0 THEN price*volume_remain ELSE 0 END) AS sell_isk,
                         COUNT(*) AS n')
            ->first();

        // Token scope check per character for the banner nudging
        // re-authorisation.
        $missingScope = DB::table('eve_market_tokens')
            ->whereIn('character_id', $characterIds)
            ->whereRaw("JSON_SEARCH(scopes, 'one', ?) IS NULL", [PersonalMarketOrdersFetcher::SCOPE_REQUIRED])
            ->pluck('character_id')
            ->map(fn ($v) => (int) $v)
            ->all();
        $noToken = array_values(array_diff(
            $characterIds,
            DB::table('eve_market_tokens')->whereIn('character_id', $characterIds)->pluck('character_id')->map(fn ($v) => (int) $v)->all(),
        ));

        return [
            'no_user' => false,
            'no_characters' => false,
            'characters' => $characters,
            'character_meta' => $charMeta,
            'main_character_id' => $mainCharId,
            'state_mode' => $stateMode,
            'character_filter' => $characterFilter,
            'orders' => $orders,
            'locations' => $locations,
            'totals_open' => $totalsOpen,
            'missing_scope_character_ids' => $missingScope,
            'no_token_character_ids' => $noToken,
        ];
    }
}

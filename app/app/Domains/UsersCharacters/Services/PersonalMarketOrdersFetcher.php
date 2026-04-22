<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Services;

use App\Domains\UsersCharacters\Models\EveMarketToken;
use App\Reference\Models\SolarSystem;
use App\Services\Eve\Esi\EsiClientInterface;
use App\Services\Eve\MarketTokenAuthorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Fetch + persist a character's personal market orders (open + history).
 *
 * Two ESI endpoints feed `personal_market_orders`:
 *   - /characters/{id}/orders/          → state = 'open'
 *   - /characters/{id}/orders/history/  → state from ESI enum
 *                                          (expired/cancelled/closed/pending),
 *                                          paginated up to ~90 days
 *
 * The same order_id rolls from /orders/ to /orders/history/ when a
 * sale completes or the order expires, so we upsert on PK and let
 * the state column track the transition.
 *
 * Scope requirement: esi-markets.read_character_orders.v1. If the
 * token lacks it (pre-scope-expansion tokens), the caller logs a
 * warning and skips; a prompt on /portal/account-settings nudges
 * the user to re-authorise.
 *
 * Plane-boundary: one character per call, typically < 1000 rows
 * (open + 90d history); stays well under the 100-row job budget
 * per dispatch. Upsert batch size is 500 to match Laravel's
 * single-statement cap.
 */
final class PersonalMarketOrdersFetcher
{
    public const SCOPE_REQUIRED = 'esi-markets.read_character_orders.v1';

    public function __construct(
        private readonly EsiClientInterface $esi,
        private readonly MarketTokenAuthorizer $auth,
    ) {}

    /**
     * Sync one character's orders.
     *
     * @return array{open:int, history:int}  row counts touched
     */
    public function sync(EveMarketToken $token): array
    {
        if (! $token->hasScope(self::SCOPE_REQUIRED)) {
            throw new RuntimeException(sprintf(
                'Market token for character %d is missing scope %s; re-authorise to sync orders.',
                $token->character_id,
                self::SCOPE_REQUIRED,
            ));
        }
        $access = $this->auth->freshAccessToken($token);
        $characterId = (int) $token->character_id;
        $userId = (int) $token->user_id;

        $openCount = $this->fetchOpen($characterId, $userId, $access);
        $historyCount = $this->fetchHistory($characterId, $userId, $access);

        return ['open' => $openCount, 'history' => $historyCount];
    }

    private function fetchOpen(int $characterId, int $userId, string $access): int
    {
        $resp = $this->esi->get("/characters/{$characterId}/orders/", bearerToken: $access);
        $rows = $resp->body ?? [];
        if ($rows === []) return 0;
        $this->upsertBatch($rows, $characterId, $userId, state: 'open');
        return count($rows);
    }

    private function fetchHistory(int $characterId, int $userId, string $access): int
    {
        $page = 1;
        $total = 0;
        while (true) {
            $resp = $this->esi->get(
                "/characters/{$characterId}/orders/history/",
                query: ['page' => $page],
                bearerToken: $access,
            );
            $rows = $resp->body ?? [];
            if ($rows === []) break;
            $this->upsertBatch($rows, $characterId, $userId, state: null);
            $total += count($rows);
            // CCP paginates ≤ 2500 rows/page; ≤ 90d history ⇒ a single
            // page almost always covers it. Loop until empty as a
            // safety rather than relying on header-count (EsiResponse
            // doesn't expose X-Pages).
            if (count($rows) < 1000) break;
            $page++;
            if ($page > 20) { // pathological cap
                Log::warning('personal orders history aborted at page 20', ['character_id' => $characterId]);
                break;
            }
        }
        return $total;
    }

    /**
     * @param list<array<string,mixed>> $rows  ESI response rows
     */
    private function upsertBatch(array $rows, int $characterId, int $userId, ?string $state): void
    {
        // Region lookup keyed by location_id — only public stations
        // resolve reliably; structures fall back to null and the
        // portal page shows location_id.
        $locationIds = array_values(array_unique(array_map(
            fn ($r) => (int) ($r['location_id'] ?? 0),
            $rows,
        )));
        $regionMap = [];
        if ($locationIds) {
            $stations = DB::table('ref_npc_stations')
                ->whereIn('ref_npc_stations.id', $locationIds)
                ->leftJoin('ref_solar_systems', 'ref_solar_systems.id', '=', 'ref_npc_stations.solar_system_id')
                ->select('ref_npc_stations.id AS station_id', 'ref_solar_systems.region_id AS region_id')
                ->get();
            foreach ($stations as $s) {
                if ($s->region_id) $regionMap[(int) $s->station_id] = (int) $s->region_id;
            }
            // Upwell structures: look up via market_hubs.
            $hubs = DB::table('market_hubs')
                ->whereIn('location_id', $locationIds)
                ->select('location_id', 'region_id')
                ->get();
            foreach ($hubs as $h) {
                $regionMap[(int) $h->location_id] = (int) $h->region_id;
            }
        }

        $now = now();
        $stateEnum = ['expired', 'cancelled', 'closed', 'pending', 'open'];
        $batch = [];
        foreach ($rows as $r) {
            $orderId = (int) ($r['order_id'] ?? 0);
            if ($orderId <= 0) continue;
            $locId = (int) ($r['location_id'] ?? 0);
            $rowState = $state ?? (string) ($r['state'] ?? 'unknown');
            if (! in_array($rowState, $stateEnum, true)) $rowState = 'unknown';
            $batch[] = [
                'order_id' => $orderId,
                'character_id' => $characterId,
                'user_id' => $userId > 0 ? $userId : null,
                'type_id' => (int) ($r['type_id'] ?? 0),
                'location_id' => $locId,
                'region_id' => $regionMap[$locId] ?? ((int) ($r['region_id'] ?? 0)),
                'is_buy' => (bool) ($r['is_buy_order'] ?? false),
                'price' => (float) ($r['price'] ?? 0),
                'volume_total' => (int) ($r['volume_total'] ?? 0),
                'volume_remain' => (int) ($r['volume_remain'] ?? 0),
                'min_volume' => (int) ($r['min_volume'] ?? 1),
                'duration' => (int) ($r['duration'] ?? 0),
                'issued' => isset($r['issued']) ? date('Y-m-d H:i:s', strtotime((string) $r['issued'])) : $now,
                'state' => $rowState,
                'is_corporation' => (bool) ($r['is_corporation'] ?? false),
                'order_range' => isset($r['range']) ? (string) $r['range'] : null,
                'first_observed_at' => $now,
                'last_observed_at' => $now,
                'observed_at' => $now,
            ];
        }
        if ($batch === []) return;

        // upsert chunks of 500 to stay under single-statement size.
        foreach (array_chunk($batch, 500) as $chunk) {
            DB::table('personal_market_orders')->upsert(
                $chunk,
                ['order_id'],
                [
                    'character_id', 'user_id', 'type_id', 'location_id', 'region_id',
                    'is_buy', 'price', 'volume_total', 'volume_remain', 'min_volume',
                    'duration', 'issued', 'state', 'is_corporation', 'order_range',
                    'last_observed_at', 'observed_at',
                ],
            );
        }
    }
}

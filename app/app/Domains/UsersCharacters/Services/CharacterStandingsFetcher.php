<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Services;

use App\Domains\UsersCharacters\Models\Character;
use App\Domains\UsersCharacters\Models\CharacterStanding;
use App\Domains\UsersCharacters\Models\EveMarketToken;
use App\Services\Eve\Esi\EsiClientInterface;
use App\Services\Eve\Esi\EsiException;
use App\Services\Eve\Esi\EsiRateLimitException;
use App\Services\Eve\MarketTokenAuthorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sync corp + alliance standings for a donor, via their market token.
 *
 * Flow per invocation ({@see sync()}):
 *
 *   1. Refresh the donor's access token (row-locked via authorizer).
 *   2. Resolve the donor character's current affiliation:
 *        GET /characters/{id}/  →  corporation_id, alliance_id
 *      Mirror the result back into `characters` so the rest of the app
 *      stays consistent (Character::corporation_id / alliance_id are
 *      phase-2 "filled later by the affiliation poller" — this call
 *      is effectively that poller for the donor-self path).
 *   3. If the token grants `esi-corporations.read_contacts.v1`, fetch
 *        GET /corporations/{corp_id}/contacts/?page=N
 *      paginated until empty. 403 is expected for line members (CCP
 *      requires Personnel_Manager or Contact_Manager role) — log at
 *      info and skip, not an error.
 *   4. If the token grants `esi-alliances.read_contacts.v1` AND the
 *      character is in an alliance, fetch
 *        GET /alliances/{alliance_id}/contacts/?page=N
 *      similarly paginated. Any alliance member can read; missing
 *      alliance_id is also a skip (corp not in an alliance).
 *   5. Upsert rows into `character_standings` keyed by
 *      (owner_type, owner_id, contact_id). Stale rows that were in the
 *      contact list last sync but aren't now get pruned so the table
 *      never accumulates orphaned "ex-friends".
 *
 * ESI pagination:
 *
 *   CCP returns `X-Pages` on the contacts endpoints, but EsiResponse
 *   doesn't surface response headers. We just walk pages until an
 *   empty body; capped at {@see self::MAX_PAGES} to prevent runaway.
 *   Typical alliance has ≤1 page (1000 contacts per page is plenty).
 *
 * Idempotence:
 *
 *   Multiple donors in the same corp/alliance all sync the same list.
 *   The unique key ensures a single row per (owner, contact); the
 *   `source_character_id` + `synced_at` columns record the last
 *   writer. Running sync() twice for the same donor is a no-op
 *   (upsert with identical data).
 *
 * Return contract: the {@see StandingsSyncResult} DTO surfaces enough
 * detail for /account/settings to give the donor precise feedback —
 * "alliance sync succeeded (142 contacts), corp skipped (no role)" —
 * without the UI layer needing to know about ESI status codes.
 */
final class CharacterStandingsFetcher
{
    /**
     * Upper bound on pages walked per (corp|alliance) contact list.
     * 1000 per page × 20 = 20,000 contacts. No real EVE entity has
     * anywhere near that. Guard against a malformed X-Pages story
     * from CCP pinning us in a loop.
     */
    public const MAX_PAGES = 20;

    public const SCOPE_CORP_CONTACTS = 'esi-corporations.read_contacts.v1';

    public const SCOPE_ALLIANCE_CONTACTS = 'esi-alliances.read_contacts.v1';

    public function __construct(
        private readonly EsiClientInterface $esi,
        private readonly MarketTokenAuthorizer $authorizer,
    ) {}

    /**
     * Sync standings for the donor backing `$token`.
     *
     * Never throws for per-endpoint ESI errors — those go into the
     * result DTO as human-readable skip reasons. Only a hard failure
     * (token refresh, affiliation lookup) throws, since those stop
     * the whole sync before any standings work starts.
     *
     * @throws RuntimeException when token refresh or affiliation
     *         resolution fails and no syncing is possible.
     */
    public function sync(EveMarketToken $token): StandingsSyncResult
    {
        $accessToken = $this->authorizer->freshAccessToken($token);

        $affiliation = $this->resolveAffiliation($token->character_id, $accessToken);
        $corpId = $affiliation['corporation_id'] ?? null;
        $allianceId = $affiliation['alliance_id'] ?? null;

        if ($corpId === null) {
            // /characters/{id}/ always returns corporation_id for a
            // live character. A null here means CCP returned a shape
            // we don't recognise — refuse rather than plough on.
            throw new RuntimeException(
                'Could not resolve donor character corporation — standings sync aborted.',
            );
        }

        // Backfill the local characters row so the rest of the app
        // sees current affiliation (phase-2 plan was a dedicated
        // affiliation poller; this is it for donor-self).
        Character::query()
            ->where('character_id', $token->character_id)
            ->update([
                'corporation_id' => $corpId,
                'alliance_id' => $allianceId,
            ]);

        $result = new StandingsSyncResult(
            characterId: $token->character_id,
            corporationId: $corpId,
            allianceId: $allianceId,
        );

        $this->syncOwner(
            token: $token,
            accessToken: $accessToken,
            ownerType: CharacterStanding::OWNER_CORPORATION,
            ownerId: $corpId,
            scopePath: "/corporations/{$corpId}/contacts/",
            requiredScope: self::SCOPE_CORP_CONTACTS,
            result: $result,
        );

        if ($allianceId !== null) {
            $this->syncOwner(
                token: $token,
                accessToken: $accessToken,
                ownerType: CharacterStanding::OWNER_ALLIANCE,
                ownerId: $allianceId,
                scopePath: "/alliances/{$allianceId}/contacts/",
                requiredScope: self::SCOPE_ALLIANCE_CONTACTS,
                result: $result,
            );
        } else {
            $result->markSkipped(
                CharacterStanding::OWNER_ALLIANCE,
                'Character is not in an alliance.',
            );
        }

        // Resolve display names for any rows lacking one. Runs once
        // per sync across both owners so a donor's corp + alliance
        // contacts share a single names round-trip (cheaper + keeps
        // the limiter happy).
        $this->resolveContactNames();

        return $result;
    }

    /**
     * GET /characters/{id}/ → ['corporation_id' => int, 'alliance_id' => ?int].
     *
     * @return array{corporation_id: ?int, alliance_id: ?int}
     */
    private function resolveAffiliation(int $characterId, string $accessToken): array
    {
        try {
            // publicData endpoint; the bearer is optional but passing
            // it is free and avoids any rate-limit group mismatch
            // between auth'd and unauth'd traffic.
            $response = $this->esi->get(
                "/characters/{$characterId}/",
                bearerToken: $accessToken,
            );
        } catch (EsiException $e) {
            Log::warning('character affiliation lookup failed', [
                'character_id' => $characterId,
                'status' => $e->status,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                'Could not look up character affiliation — ESI may be degraded. Try again shortly.',
                previous: $e,
            );
        }

        $body = $response->body ?? [];
        $corpId = isset($body['corporation_id']) ? (int) $body['corporation_id'] : null;
        $allianceId = isset($body['alliance_id']) ? (int) $body['alliance_id'] : null;

        return [
            'corporation_id' => $corpId,
            'alliance_id' => $allianceId,
        ];
    }

    /**
     * Fetch paginated contacts for one owner (corp OR alliance), upsert
     * them into `character_standings`, prune stale rows, record outcome
     * on `$result`.
     */
    private function syncOwner(
        EveMarketToken $token,
        string $accessToken,
        string $ownerType,
        int $ownerId,
        string $scopePath,
        string $requiredScope,
        StandingsSyncResult $result,
    ): void {
        if (! $token->hasScope($requiredScope)) {
            // Donor authorised before the scope was added to
            // market_scopes, or CCP dropped it on a refresh. Skip
            // with a clear message the UI can surface.
            $result->markSkipped(
                $ownerType,
                "Token missing scope {$requiredScope} — re-authorise to enable {$ownerType} standings.",
            );

            return;
        }

        $rows = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            try {
                $response = $this->esi->get(
                    $scopePath,
                    query: ['page' => $page],
                    bearerToken: $accessToken,
                );
            } catch (EsiRateLimitException $e) {
                // Partial sync is worse than no sync for a paginated
                // list (we'd prune rows we didn't actually see this
                // round). Skip the whole owner this run.
                Log::warning('standings sync hit rate limit', [
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                    'page' => $page,
                    'retry_after' => $e->retryAfter,
                ]);
                $result->markSkipped(
                    $ownerType,
                    'ESI rate-limited — try again in a few minutes.',
                );

                return;
            } catch (EsiException $e) {
                // 403 on /corporations/{id}/contacts/ is the common
                // case — donor is a line member without Personnel_Manager
                // / Contact_Manager role. Surface as a skip, not an
                // error; /account/settings explains it.
                $reason = $e->status === 403
                    ? ($ownerType === CharacterStanding::OWNER_CORPORATION
                        ? 'Character lacks Personnel_Manager or Contact_Manager role on this corp.'
                        : 'Character is not permitted to read this alliance\'s contacts.')
                    : "ESI returned HTTP {$e->status} fetching {$ownerType} contacts.";

                Log::info('standings sync owner fetch failed', [
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                    'page' => $page,
                    'status' => $e->status,
                    'error' => $e->getMessage(),
                ]);
                $result->markSkipped($ownerType, $reason);

                return;
            }

            $body = $response->body ?? [];
            if (! is_array($body) || $body === []) {
                // Empty page → we've walked past the last populated
                // one. Normal termination.
                break;
            }

            foreach ($body as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $contactId = isset($entry['contact_id']) ? (int) $entry['contact_id'] : 0;
                $contactType = isset($entry['contact_type']) ? (string) $entry['contact_type'] : '';
                $standing = $entry['standing'] ?? null;
                if ($contactId === 0 || $contactType === '' || $standing === null) {
                    continue;
                }
                if (! in_array($contactType, [
                    CharacterStanding::CONTACT_CHARACTER,
                    CharacterStanding::CONTACT_CORPORATION,
                    CharacterStanding::CONTACT_ALLIANCE,
                    CharacterStanding::CONTACT_FACTION,
                ], true)) {
                    // CCP occasionally adds new contact_type values.
                    // Drop silently rather than let a bogus ENUM
                    // insert sink the transaction.
                    continue;
                }

                $rows[] = [
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                    'contact_id' => $contactId,
                    'contact_type' => $contactType,
                    'standing' => number_format((float) $standing, 1, '.', ''),
                    'label_ids' => isset($entry['label_ids']) && is_array($entry['label_ids'])
                        ? json_encode(array_values($entry['label_ids']))
                        : null,
                ];
            }
        }

        $count = $this->upsertAndPrune(
            token: $token,
            ownerType: $ownerType,
            ownerId: $ownerId,
            rows: $rows,
        );

        $result->markSynced($ownerType, $count);
    }

    /**
     * Transactionally upsert the fresh row set for one owner and
     * delete rows that are no longer in the contact list.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function upsertAndPrune(
        EveMarketToken $token,
        string $ownerType,
        int $ownerId,
        array $rows,
    ): int {
        $sourceCharacter = Character::query()
            ->where('character_id', $token->character_id)
            ->value('id');

        $now = now();

        return DB::transaction(function () use ($ownerType, $ownerId, $rows, $sourceCharacter, $now): int {
            if ($rows !== []) {
                $upsertRows = array_map(static function (array $row) use ($sourceCharacter, $now): array {
                    return array_merge($row, [
                        'source_character_id' => $sourceCharacter,
                        'synced_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }, $rows);

                // Chunk to stay under common max_allowed_packet limits
                // on MariaDB (each row is ~200 bytes; 500 per chunk is
                // well under 1MB).
                foreach (array_chunk($upsertRows, 500) as $chunk) {
                    CharacterStanding::query()->upsert(
                        values: $chunk,
                        uniqueBy: ['owner_type', 'owner_id', 'contact_id'],
                        update: [
                            'contact_type',
                            'standing',
                            'label_ids',
                            'source_character_id',
                            'synced_at',
                            'updated_at',
                        ],
                    );
                }
            }

            // Prune rows for this owner that weren't in the latest sync.
            // Using synced_at < $now is safe because upsert() above
            // bumped every surviving row's synced_at to exactly $now.
            $pruned = CharacterStanding::query()
                ->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId)
                ->where('synced_at', '<', $now)
                ->delete();

            if ($pruned > 0) {
                Log::info('standings sync pruned stale contacts', [
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                    'pruned' => $pruned,
                ]);
            }

            return count($rows);
        });
    }

    /**
     * Resolve `contact_name` for any standings rows still missing one
     * via POST /universe/names/ (unauth'd, batched, cached 1h by ESI).
     *
     * Runs across the whole table each sync, not scoped to one owner,
     * because:
     *
     *   1. The same contact_id often appears in both a corp and
     *      alliance list — resolving once covers both.
     *   2. The batch endpoint is cheap (1 call per 1000 IDs); walking
     *      the full unresolved set keeps name coverage monotonic even
     *      if a prior sync crashed mid-resolve.
     *
     * Tolerant of failure — a name-resolve hiccup must not unwind the
     * standings upsert, which is the load-bearing data for the
     * battle-report downstream. Rows just keep the null name and the
     * UI falls back to the numeric ID.
     */
    private function resolveContactNames(): void
    {
        $ids = CharacterStanding::query()
            ->whereNull('contact_name')
            ->pluck('contact_id')
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        $resolved = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            try {
                $names = $this->postUniverseNames($chunk);
            } catch (Throwable $e) {
                Log::warning('standings sync name resolve failed', [
                    'chunk_size' => count($chunk),
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($names as $entry) {
                $id = (int) ($entry['id'] ?? 0);
                $name = (string) ($entry['name'] ?? '');
                if ($id === 0 || $name === '') {
                    continue;
                }
                $resolved[$id] = $name;
            }
        }

        if ($resolved === []) {
            return;
        }

        // Update in one pass per id — small set (dozens to low
        // hundreds typical), so avoid a giant CASE expression in
        // favour of N simple updates. Transaction keeps the mass
        // write atomic against concurrent syncs from another donor
        // in the same corp/alliance.
        DB::transaction(function () use ($resolved): void {
            foreach ($resolved as $contactId => $name) {
                CharacterStanding::query()
                    ->where('contact_id', $contactId)
                    ->whereNull('contact_name')
                    ->update(['contact_name' => $name]);
            }
        });
    }

    /**
     * POST /universe/names/ — resolve IDs → names/categories in one
     * batch. Unauth'd, up to 1000 IDs per call. Mirrors
     * PollDonationsWallet::postUniverseNames() — consolidate into a
     * shared service when a third caller appears.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{id: int, name: string, category: string}>
     */
    private function postUniverseNames(array $ids): array
    {
        $baseUrl = (string) config('eve.esi.base_url', 'https://esi.evetech.net/latest');
        $userAgent = (string) config('eve.esi.user_agent', 'AegisCore/0.1');
        $timeout = (int) config('eve.esi.timeout_seconds', 10);

        $response = Http::withUserAgent($userAgent)
            ->timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->post(rtrim($baseUrl, '/').'/universe/names/', $ids);

        if (! $response->successful()) {
            throw new RuntimeException(
                "/universe/names/ returned HTTP {$response->status()}",
            );
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Services;

use App\Domains\UsersCharacters\Models\Character;
use App\Domains\UsersCharacters\Models\CharacterStanding;
use App\Domains\UsersCharacters\Models\CharacterStandingLabel;
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
 * Sync corp + alliance standings (with character-contacts fallback)
 * for a donor, via their market token.
 *
 * Flow per invocation ({@see sync()}):
 *
 *   1. Refresh the donor's access token (row-locked via authorizer).
 *   2. Resolve the donor character's current affiliation:
 *        GET esi.evetech.net/characters/{id}  →  corporation_id, alliance_id
 *      (new unversioned ESI; see {@see resolveAffiliation()} for why
 *      the legacy `/latest/` path isn't viable here any more, and for
 *      the local `characters`-table fallback when ESI returns an
 *      incomplete shape). Mirror the resolved result back into
 *      `characters` so the rest of the app stays consistent.
 *   3. If the token grants `esi-corporations.read_contacts.v1`, fetch
 *        GET /corporations/{corp_id}/contacts + contacts/labels
 *      on the new unversioned ESI (`esi.evetech.net`, no `/latest/`,
 *      with `X-Compatibility-Date`). 403 is expected for line members
 *      (CCP requires Personnel_Manager or Contact_Manager role) — log
 *      at info, mark skipped, keep going.
 *   4. If the token grants `esi-alliances.read_contacts.v1` AND the
 *      character is in an alliance, fetch
 *        GET /alliances/{alliance_id}/contacts + contacts/labels.
 *      Any alliance member can read.
 *   5. Fallback: if BOTH corp and alliance came back empty/skipped, and
 *      the token grants `esi-characters.read_contacts.v1`, fetch
 *        GET /characters/{id}/contacts + contacts/labels
 *      so a solo NPC-corp donor still gets *something* for the battle-
 *      report downstream. Display still hides contact_type='character'.
 *   6. Upsert into `character_standings` keyed by
 *      (owner_type, owner_id, contact_id); prune stale rows.
 *   7. Upsert into `character_standing_labels` keyed by
 *      (owner_type, owner_id, label_id); prune stale rows.
 *
 * Why the new unversioned ESI for every call:
 *
 *   CCP is migrating from /latest/ (versioned endpoints) to
 *   esi.evetech.net (unversioned + X-Compatibility-Date header). The
 *   contacts endpoints are only present on the new path; /latest/
 *   returns 404 for both corp and alliance contacts as of 2026-04.
 *   `/latest/characters/{id}/` was also observed returning an
 *   incomplete shape (no `corporation_id`) for some donors, blocking
 *   the sync at step 2 even when the contacts calls would have
 *   succeeded. Every fetcher call now goes to the new ESI; the
 *   shared EsiClient handles that transparently via absolute URLs.
 *
 * ESI pagination:
 *
 *   CCP returns `X-Pages`, but EsiResponse doesn't surface response
 *   headers. We walk pages until an empty body; capped at
 *   {@see self::MAX_PAGES} to prevent runaway.
 *
 * Idempotence:
 *
 *   Multiple donors in the same corp/alliance all sync the same list.
 *   The unique key ensures a single row per (owner, contact); the
 *   `source_character_id` + `synced_at` columns record the last
 *   writer. Running sync() twice for the same donor is a no-op.
 *
 * Return contract: the {@see StandingsSyncResult} DTO surfaces enough
 * detail for /account/settings to give the donor precise feedback —
 * "alliance sync succeeded (142 contacts), corp skipped (no role)" —
 * without the UI layer needing to know about ESI status codes.
 */
final class CharacterStandingsFetcher
{
    /**
     * Upper bound on pages walked per contact list.
     * 1000 per page × 20 = 20,000 contacts. No real EVE entity has
     * anywhere near that. Guard against a malformed X-Pages story
     * from CCP pinning us in a loop.
     */
    public const MAX_PAGES = 20;

    public const SCOPE_CORP_CONTACTS = 'esi-corporations.read_contacts.v1';

    public const SCOPE_ALLIANCE_CONTACTS = 'esi-alliances.read_contacts.v1';

    public const SCOPE_CHARACTER_CONTACTS = 'esi-characters.read_contacts.v1';

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
            // Both the ESI lookup AND the local `characters`-table
            // fallback returned null — either this is a brand-new
            // donor whose affiliation has never been mirrored and CCP
            // happened to be down on this sync, or the donor's local
            // row was manually cleared. Either way we can't
            // usefully proceed; surface a message that points at
            // retrying rather than at a permanent failure.
            throw new RuntimeException(
                'Could not resolve donor character corporation — ESI degraded and no local affiliation cached. Try again shortly.',
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
            contactsPath: "/corporations/{$corpId}/contacts",
            labelsPath: "/corporations/{$corpId}/contacts/labels",
            requiredScope: self::SCOPE_CORP_CONTACTS,
            result: $result,
        );

        if ($allianceId !== null) {
            $this->syncOwner(
                token: $token,
                accessToken: $accessToken,
                ownerType: CharacterStanding::OWNER_ALLIANCE,
                ownerId: $allianceId,
                contactsPath: "/alliances/{$allianceId}/contacts",
                labelsPath: "/alliances/{$allianceId}/contacts/labels",
                requiredScope: self::SCOPE_ALLIANCE_CONTACTS,
                result: $result,
            );
        } else {
            $result->markSkipped(
                CharacterStanding::OWNER_ALLIANCE,
                'Character is not in an alliance.',
            );
        }

        // Fallback path: if neither group-level source succeeded, try
        // the donor's personal contact list. Better than a completely
        // empty table for a solo NPC-corp donor.
        $corpOk = $result->byOwner()[CharacterStanding::OWNER_CORPORATION]['status'] ?? null;
        $allianceOk = $result->byOwner()[CharacterStanding::OWNER_ALLIANCE]['status'] ?? null;
        $corpHasRows = ($result->byOwner()[CharacterStanding::OWNER_CORPORATION]['count'] ?? 0) > 0;
        $allianceHasRows = ($result->byOwner()[CharacterStanding::OWNER_ALLIANCE]['count'] ?? 0) > 0;

        if (! $corpHasRows && ! $allianceHasRows) {
            $this->syncOwner(
                token: $token,
                accessToken: $accessToken,
                ownerType: CharacterStanding::OWNER_CHARACTER,
                ownerId: $token->character_id,
                contactsPath: "/characters/{$token->character_id}/contacts",
                labelsPath: "/characters/{$token->character_id}/contacts/labels",
                requiredScope: self::SCOPE_CHARACTER_CONTACTS,
                result: $result,
            );
        }

        // Resolve display names for any rows lacking one. Runs once
        // per sync across all owners so contacts shared across lists
        // (same alliance appears in corp AND character contacts) get
        // a single names round-trip.
        $this->resolveContactNames();

        return $result;
    }

    /**
     * Resolve the donor character's current affiliation — returning
     * `corporation_id` and optional `alliance_id`.
     *
     * Primary source: the new unversioned ESI's `/characters/{id}`
     * endpoint (with `X-Compatibility-Date`). `/latest/characters/{id}/`
     * has been observed returning a shape without `corporation_id`
     * for some characters, which is what fires the
     * "Could not resolve donor character corporation" error — moving
     * to the new ESI keeps the lookup aligned with the contacts
     * endpoints that already live there.
     *
     * Fallback: if the ESI call returns an incomplete shape (CCP
     * transient, schema drift, partial payload), fall back to the
     * locally-stored `characters.corporation_id` / `alliance_id`,
     * which the previous successful sync mirrored in. Better to sync
     * against slightly-stale affiliation than to abort entirely on
     * the sole basis of a flaky lookup.
     *
     * @return array{corporation_id: ?int, alliance_id: ?int}
     */
    private function resolveAffiliation(int $characterId, string $accessToken): array
    {
        $corpId = null;
        $allianceId = null;

        try {
            $response = $this->esi->get(
                $this->newEsiUrl("/characters/{$characterId}"),
                bearerToken: $accessToken,
                headers: ['X-Compatibility-Date' => $this->compatDate()],
            );

            $body = $response->body ?? [];
            $corpId = isset($body['corporation_id']) ? (int) $body['corporation_id'] : null;
            $allianceId = isset($body['alliance_id']) ? (int) $body['alliance_id'] : null;
        } catch (EsiException $e) {
            // Don't throw here — check the local fallback first. ESI
            // degraded + stored affiliation valid is a recoverable
            // state; throwing before checking would block the sync
            // unnecessarily.
            Log::info('character affiliation ESI lookup failed, trying local fallback', [
                'character_id' => $characterId,
                'status' => $e->status,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback to the locally-mirrored affiliation when the ESI
        // call didn't produce a corporation_id. Alliance is always
        // allowed to be null (solo corp), so we only fall back when
        // corp is missing.
        if ($corpId === null) {
            $local = Character::query()
                ->where('character_id', $characterId)
                ->first(['corporation_id', 'alliance_id']);

            if ($local !== null && $local->corporation_id !== null) {
                Log::info('character affiliation resolved from local fallback', [
                    'character_id' => $characterId,
                    'corporation_id' => $local->corporation_id,
                    'alliance_id' => $local->alliance_id,
                ]);
                $corpId = (int) $local->corporation_id;
                $allianceId = $local->alliance_id !== null
                    ? (int) $local->alliance_id
                    : null;
            }
        }

        return [
            'corporation_id' => $corpId,
            'alliance_id' => $allianceId,
        ];
    }

    /**
     * Fetch contacts + labels for one owner on the new unversioned
     * ESI, upsert everything into the standings + labels tables,
     * prune stale rows, record outcome on `$result`.
     *
     * `$contactsPath` / `$labelsPath` are relative paths under the
     * new-ESI base (e.g. `/alliances/123/contacts`). The fetcher
     * resolves them into absolute URLs so the shared EsiClient's
     * rate-limit + payload cache still applies, just against a
     * different host.
     */
    private function syncOwner(
        EveMarketToken $token,
        string $accessToken,
        string $ownerType,
        int $ownerId,
        string $contactsPath,
        string $labelsPath,
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

        $contactsUrl = $this->newEsiUrl($contactsPath);
        $labelsUrl = $this->newEsiUrl($labelsPath);
        $compatHeaders = ['X-Compatibility-Date' => $this->compatDate()];

        $rows = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            try {
                $response = $this->esi->get(
                    $contactsUrl,
                    query: ['page' => $page],
                    bearerToken: $accessToken,
                    headers: $compatHeaders,
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
                // Map common statuses to human-readable skip reasons.
                $reason = match (true) {
                    $e->status === 403 && $ownerType === CharacterStanding::OWNER_CORPORATION
                        => 'Character lacks Personnel_Manager or Contact_Manager role on this corp.',
                    $e->status === 403
                        => "Character is not permitted to read this {$ownerType}'s contacts.",
                    // 404 is CCP's way of saying "this owner has no
                    // contacts list" for some donors / NPC corps. Not
                    // an error; mark an empty skip.
                    $e->status === 404
                        => "No {$ownerType} contact list published by CCP for this entity.",
                    default
                        => "ESI returned HTTP {$e->status} fetching {$ownerType} contacts.",
                };

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

        // Labels are best-effort — a failure here shouldn't unwind the
        // standings upsert that just succeeded. If the fetch 404s or
        // errors, we skip label storage for this owner and the UI
        // falls back to rendering `#<label_id>` badges.
        $this->syncOwnerLabels(
            accessToken: $accessToken,
            ownerType: $ownerType,
            ownerId: $ownerId,
            labelsUrl: $labelsUrl,
            compatHeaders: $compatHeaders,
        );

        $result->markSynced($ownerType, $count);
    }

    /**
     * Fetch the `/contacts/labels` sibling endpoint for one owner and
     * upsert/prune into `character_standing_labels`. Label endpoints
     * aren't paginated in the CCP spec — one GET returns the full list.
     *
     * @param  array<string, string>  $compatHeaders
     */
    private function syncOwnerLabels(
        string $accessToken,
        string $ownerType,
        int $ownerId,
        string $labelsUrl,
        array $compatHeaders,
    ): void {
        try {
            $response = $this->esi->get(
                $labelsUrl,
                bearerToken: $accessToken,
                headers: $compatHeaders,
            );
        } catch (EsiRateLimitException | EsiException $e) {
            Log::info('standings label fetch failed (non-fatal)', [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $body = $response->body ?? [];
        if (! is_array($body)) {
            return;
        }

        $rows = [];
        foreach ($body as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $labelId = isset($entry['label_id']) ? (int) $entry['label_id'] : 0;
            $labelName = isset($entry['label_name']) ? (string) $entry['label_name'] : '';
            if ($labelId === 0 || $labelName === '') {
                continue;
            }

            $rows[] = [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'label_id' => $labelId,
                'label_name' => mb_substr($labelName, 0, 100),
            ];
        }

        $now = now();
        DB::transaction(function () use ($ownerType, $ownerId, $rows, $now): void {
            if ($rows !== []) {
                $upsertRows = array_map(static function (array $row) use ($now): array {
                    return array_merge($row, [
                        'synced_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }, $rows);

                CharacterStandingLabel::query()->upsert(
                    values: $upsertRows,
                    uniqueBy: ['owner_type', 'owner_id', 'label_id'],
                    update: ['label_name', 'synced_at', 'updated_at'],
                );
            }

            CharacterStandingLabel::query()
                ->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId)
                ->where('synced_at', '<', $now)
                ->delete();
        });
    }

    /**
     * Build a fully-qualified new-ESI URL (esi.evetech.net, no
     * `/latest/`). The shared EsiClient transparently passes absolute
     * URLs through, so this is how we mix legacy + new-ESI calls in
     * the same client instance.
     */
    private function newEsiUrl(string $path): string
    {
        $base = (string) config('eve.esi.new_base_url', 'https://esi.evetech.net');

        return rtrim($base, '/').'/'.ltrim($path, '/');
    }

    private function compatDate(): string
    {
        return (string) config('eve.esi.compat_date', '2025-12-16');
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

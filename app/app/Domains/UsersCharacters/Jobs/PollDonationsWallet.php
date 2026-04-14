<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Jobs;

use App\Domains\UsersCharacters\Models\EveDonation;
use App\Domains\UsersCharacters\Models\EveDonationsToken;
use App\Services\Eve\Esi\EsiClient;
use App\Services\Eve\Esi\EsiException;
use App\Services\Eve\Esi\EsiRateLimitException;
use App\Services\Eve\Sso\EveSsoClient;
use App\Services\Eve\Sso\EveSsoException;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Poll the donations character's wallet journal, persist new donor events.
 *
 * Job sequence on each tick:
 *
 *   1. Load the single `eve_donations_tokens` row. Bail (no-op) if no
 *      donations character has been authorised yet — this is the safe
 *      default, not an error condition.
 *   2. If the access token is stale, refresh via
 *      {@see EveSsoClient::refreshAccessToken()}. CCP rotates the
 *      refresh token on every call; persist the new one before doing
 *      anything else so a failure between refresh + use doesn't lose
 *      the credential.
 *   3. GET /characters/{id}/wallet/journal — paginated, but page 1 alone
 *      covers ~250 most-recent entries which at the donations cadence
 *      (dozens of donors total, single-digit donations per week) is far
 *      more than five minutes' worth. We deliberately do NOT walk
 *      pagination: the upsert is idempotent, so any backfill only needs
 *      a one-off `--full-history` mode (not implemented yet — open it
 *      when there's a real backfill need).
 *   4. Filter to `ref_type === 'player_donation'`, upsert by
 *      `journal_ref_id` (CCP's primary key for the journal entry) so
 *      replaying the same page is a no-op.
 *   5. For freshly-inserted rows missing a donor_name, POST to
 *      /universe/names/ (unauth'd) to resolve names in one batch, then
 *      backfill the column.
 *
 * Plane-boundary note: a donations community of dozens of donors means
 * each poll touches at most a handful of new rows; this stays well
 * inside the < 100-row guidance from AGENTS.md § "Job placement rule".
 * If the donor base ever grows large enough that this no longer fits,
 * the job hands off to the Python execution plane and the Laravel
 * scheduler entry just dispatches a Python task instead. ADR-0002 §
 * phase-2 amendment.
 *
 * Concurrency: a single scheduler instance is the operating model in
 * phase 1; no distributed lock is taken on the token row. If/when we
 * scale to multiple schedulers, add a row-level advisory lock on the
 * token's primary key before the refresh step (token rotation breaks
 * if two pollers refresh in parallel).
 */
class PollDonationsWallet implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * One try. The 5-minute schedule is the retry policy: if a single
     * tick fails, the next one will pick up exactly the same wallet
     * page (CCP keeps journal entries for ~30 days) and the upsert by
     * journal_ref_id keeps it idempotent. Burning Horizon worker time
     * on retries inside one tick risks compounding rate-limit pressure
     * with no extra coverage.
     */
    public int $tries = 1;

    /**
     * Generous timeout: token refresh + one ESI page + one names POST.
     * Each network call has its own per-request timeout in EsiClient /
     * the Http facade — this guards against unexpected sleeps in the
     * rate-limiter pre-flight.
     */
    public int $timeout = 60;

    public function handle(EsiClient $esi): void
    {
        $token = EveDonationsToken::query()->first();
        if ($token === null) {
            // Common steady-state path before the admin has authorised
            // the donations character. Log at debug so the scheduler
            // logs aren't noisy on a fresh stack.
            Log::debug('eve:poll-donations skipped — no donations token authorised yet');

            return;
        }

        $expectedCharacterId = (int) (config('eve.sso.donations.character_id') ?? 0);
        if ($expectedCharacterId === 0 || $token->character_id !== $expectedCharacterId) {
            // The token in storage doesn't match the env-configured
            // character. Refuse to use it rather than poll the wrong
            // wallet. The SSO callback already rejects wrong-character
            // authorisations, so this state should be unreachable in
            // practice — defensive coding for the case where the env
            // var was changed after a token was already stored.
            Log::warning('eve:poll-donations skipped — token character_id does not match config', [
                'token_character_id' => $token->character_id,
                'expected_character_id' => $expectedCharacterId,
            ]);

            return;
        }

        try {
            $accessToken = $this->ensureFreshAccessToken($token);
        } catch (EveSsoException $e) {
            Log::error('eve:poll-donations refresh failed', [
                'character_id' => $token->character_id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        try {
            $response = $esi->get(
                path: "/characters/{$token->character_id}/wallet/journal/",
                bearerToken: $accessToken,
            );
        } catch (EsiRateLimitException $e) {
            // Skip this tick, next one picks up. Don't release back onto
            // the queue — schedule cadence IS the retry.
            Log::warning('eve:poll-donations hit rate limit', [
                'retry_after' => $e->retryAfter,
            ]);

            return;
        } catch (EsiException $e) {
            Log::warning('eve:poll-donations wallet fetch failed', [
                'character_id' => $token->character_id,
                'status' => $e->status,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($response->notModified) {
            // Conditional GET said "no new entries since last tick".
            // Cheap path — the limiter even charges half the tokens.
            Log::debug('eve:poll-donations 304 — wallet journal unchanged');

            return;
        }

        /** @var array<int, array<string, mixed>> $entries */
        $entries = is_array($response->body) ? $response->body : [];
        $donations = $this->extractDonations($entries);

        if ($donations === []) {
            return;
        }

        $insertedCharacterIds = $this->upsertDonations($donations);
        $this->resolveDonorNames($insertedCharacterIds);
    }

    /**
     * Returns a usable bearer token, refreshing + persisting rotated
     * credentials when the stored access token is too close to expiry.
     */
    private function ensureFreshAccessToken(EveDonationsToken $token): string
    {
        if ($token->isAccessTokenFresh()) {
            return $token->access_token;
        }

        $sso = EveSsoClient::fromConfig();
        $refreshed = $sso->refreshAccessToken($token->refresh_token);

        // CCP can drop a previously-granted scope on refresh if the user
        // revoked it on the third-party-applications page. Persist the
        // new scope set + warn so the operator notices before the
        // wallet endpoint starts 403'ing.
        $missingWalletScope = ! in_array(
            'esi-wallet.read_character_wallet.v1',
            $refreshed->scopes,
            true,
        );
        if ($missingWalletScope) {
            Log::warning('eve:poll-donations refresh dropped wallet scope', [
                'character_id' => $refreshed->characterId,
                'scopes' => $refreshed->scopes,
            ]);
        }

        $token->forceFill([
            'character_name' => $refreshed->characterName,
            'scopes' => $refreshed->scopes,
            'access_token' => $refreshed->accessToken,
            'refresh_token' => $refreshed->refreshToken,
            'expires_at' => CarbonImmutable::now()->addSeconds(max(0, $refreshed->expiresIn)),
        ])->save();

        return $refreshed->accessToken;
    }

    /**
     * Filter the wallet-journal payload to player_donation rows and
     * normalise into the eve_donations row shape.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array{
     *   journal_ref_id: int,
     *   donor_character_id: int,
     *   amount: string,
     *   reason: ?string,
     *   donated_at: \Carbon\CarbonImmutable
     * }>
     */
    private function extractDonations(array $entries): array
    {
        $rows = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (($entry['ref_type'] ?? null) !== 'player_donation') {
                continue;
            }

            $donorId = (int) ($entry['first_party_id'] ?? 0);
            $journalRefId = (int) ($entry['id'] ?? 0);
            $amount = $entry['amount'] ?? null;
            $dateRaw = (string) ($entry['date'] ?? '');

            // Defensive: a malformed entry shouldn't sink the whole tick
            // — drop it and keep going.
            if ($donorId === 0 || $journalRefId === 0 || $amount === null || $dateRaw === '') {
                continue;
            }

            // Player donations always show up with a positive amount on
            // the *recipient* side. Filter out the (extremely
            // theoretical) outgoing case rather than store negatives —
            // we're polling the donations character, who never sends.
            if ((float) $amount <= 0.0) {
                continue;
            }

            $rows[] = [
                'journal_ref_id' => $journalRefId,
                'donor_character_id' => $donorId,
                // Cast through string to avoid float→string drift. CCP
                // delivers floats with up to 2dp; number_format keeps
                // the column happy at DECIMAL(20, 2).
                'amount' => number_format((float) $amount, 2, '.', ''),
                'reason' => $this->normaliseReason($entry['reason'] ?? null),
                'donated_at' => CarbonImmutable::parse($dateRaw),
            ];
        }

        return $rows;
    }

    /**
     * Upsert the rows by journal_ref_id and return the donor character
     * IDs that were *newly* inserted (not already present). Only those
     * need a name-resolve round-trip — existing rows already carry a
     * donor_name from a prior tick.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, int>
     */
    private function upsertDonations(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $journalIds = array_column($rows, 'journal_ref_id');

        // Snapshot which journal_ref_ids we already know about *before*
        // upserting. Anything not in this set is brand new and needs a
        // name-resolve pass.
        $existingJournalIds = EveDonation::query()
            ->whereIn('journal_ref_id', $journalIds)
            ->pluck('journal_ref_id')
            ->all();
        $existingSet = array_flip($existingJournalIds);

        $now = now();
        $upsertRows = array_map(static function (array $row) use ($now): array {
            return [
                'journal_ref_id' => $row['journal_ref_id'],
                'donor_character_id' => $row['donor_character_id'],
                // donor_name is resolved in a follow-up pass; leave null
                // here so the upsert doesn't blank an already-resolved
                // name on a re-run.
                'amount' => $row['amount'],
                'reason' => $row['reason'],
                'donated_at' => $row['donated_at'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $rows);

        DB::transaction(function () use ($upsertRows): void {
            EveDonation::query()->upsert(
                values: $upsertRows,
                uniqueBy: ['journal_ref_id'],
                update: ['donor_character_id', 'amount', 'reason', 'donated_at', 'updated_at'],
            );
        });

        // Collect donor IDs that came in fresh (not previously stored).
        $freshDonorIds = [];
        foreach ($rows as $row) {
            if (! isset($existingSet[$row['journal_ref_id']])) {
                $freshDonorIds[] = $row['donor_character_id'];
            }
        }

        if ($freshDonorIds !== []) {
            Log::info('eve:poll-donations recorded donations', [
                'count' => count($freshDonorIds),
                'donor_character_ids' => array_values(array_unique($freshDonorIds)),
            ]);
        }

        return array_values(array_unique($freshDonorIds));
    }

    /**
     * Resolve donor names via /universe/names/ (unauth'd, POST, batched).
     *
     * Only updates rows whose donor_name is currently null — we don't
     * spam re-resolves for characters that have already been seen. If a
     * donor renames in EVE, the next *new* donation from them picks up
     * the new name; older rows keep the original (acceptable: the
     * donation amount + date are the durable facts, the name is just a
     * display hint).
     *
     * @param array<int, int> $donorCharacterIds
     */
    private function resolveDonorNames(array $donorCharacterIds): void
    {
        if ($donorCharacterIds === []) {
            return;
        }

        // Restrict to rows that actually need a name (might be a donor
        // we already resolved on an earlier tick whose journal entry
        // got reinserted; the prior row still has a name).
        $needsResolve = EveDonation::query()
            ->whereIn('donor_character_id', $donorCharacterIds)
            ->whereNull('donor_name')
            ->pluck('donor_character_id')
            ->unique()
            ->values()
            ->all();

        if ($needsResolve === []) {
            return;
        }

        // /universe/names/ caps at 1000 IDs per call; donor batches are
        // tiny but chunk anyway to be future-proof.
        $resolved = [];
        foreach (array_chunk($needsResolve, 1000) as $chunk) {
            try {
                $names = $this->postUniverseNames($chunk);
            } catch (Throwable $e) {
                Log::warning('eve:poll-donations names resolve failed', [
                    'chunk' => $chunk,
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

        DB::transaction(function () use ($resolved): void {
            foreach ($resolved as $characterId => $name) {
                EveDonation::query()
                    ->where('donor_character_id', $characterId)
                    ->whereNull('donor_name')
                    ->update(['donor_name' => $name]);
            }
        });
    }

    /**
     * Thin wrapper around the unauth'd /universe/names/ endpoint.
     *
     * Done with the Http facade rather than EsiClient because EsiClient
     * is GET-only by design and adding a POST path for a single caller
     * would muddy the rate-limiter boundary (names is on a different
     * limit group from per-character endpoints). Move into EsiClient
     * if a second POST caller appears.
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
            throw new \RuntimeException(
                "/universe/names/ returned HTTP {$response->status()}",
            );
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * Trim + cap the free-text reason from CCP. Empty / whitespace-only
     * reasons normalise to null so the column distinguishes "no reason"
     * from "blank reason".
     */
    private function normaliseReason(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        // The migration caps the column at 500; defensive substr so a
        // malformed CCP response can't nuke the row insert.
        return mb_substr($trimmed, 0, 500);
    }
}

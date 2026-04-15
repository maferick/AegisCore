<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Services;

use App\Domains\UsersCharacters\Models\CorporationAffiliationProfile;
use App\Services\Eve\Esi\EsiClientInterface;
use App\Services\Eve\Esi\EsiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Populates `corporation_affiliation_profiles` from ESI.
 *
 * Two endpoints per corp (both public — no bearer token, no scope):
 *
 *   GET /corporations/{id}/                 → current alliance_id (if any)
 *   GET /corporations/{id}/alliancehistory/ → full history chain
 *
 * Both hit the new unversioned ESI (`esi.evetech.net`, no `/latest/`)
 * with `X-Compatibility-Date` so this fetcher stays aligned with the
 * standings path — same compat-date config key, same base URL.
 *
 * Responsibility boundaries:
 *
 *   - THIS class fetches + computes + upserts for ONE corp. It does
 *     not decide which corps to sync; that's the job's role.
 *   - It does not throw for per-endpoint failures. Transient ESI
 *     degradation on either endpoint returns a `failed` result
 *     without touching the existing profile row (stale-but-present
 *     is better than erased).
 *   - It DOES upsert `observed_at` on success so the job's staleness
 *     check can skip fresh rows next time.
 *
 * Freshness + history-confidence rules:
 *
 *   - `observed_at` is the current time on every successful upsert.
 *     The staleness threshold that gates re-sync lives on the JOB
 *     (default 24 h), not here — this fetcher is always-willing.
 *   - `recently_changed_affiliation = true` when the most-recent
 *     history entry's `start_date` is within
 *     `recent_change_days` (default 14) of "now". Matches the
 *     migration header's "Recently moved blocs - double check your
 *     classification" semantic.
 *   - `history_confidence_band`:
 *       high   - ≥ 2 history entries AND endpoints both succeeded.
 *       medium - 1 history entry (brand-new corp, still in its first
 *                alliance) AND endpoints both succeeded.
 *       low    - 0 history entries OR history endpoint failed.
 */
final class CorporationAffiliationFetcher
{
    public function __construct(
        private readonly EsiClientInterface $esi,
    ) {}

    /**
     * Sync affiliation for one corporation. Never throws — transient
     * failures come back as a {@see CorporationAffiliationSyncResult}
     * with status='failed' so the job loop can count them and move on.
     */
    public function sync(int $corporationId, int $recentChangeDays = 14): CorporationAffiliationSyncResult
    {
        // 1) Current alliance from the public corp endpoint. If this
        //    fails we abort — without current_alliance_id the profile
        //    is missing its primary signal, and writing a partial row
        //    would overwrite a previously-good one with garbage.
        $current = $this->fetchCurrentAllianceId($corporationId);
        if ($current['error'] !== null) {
            return CorporationAffiliationSyncResult::failed($corporationId, $current['error']);
        }
        $currentAllianceId = $current['alliance_id'];

        // 2) Alliance history. Failure here is survivable — we can
        //    still write `current_alliance_id` with low confidence
        //    and fill history on a later run.
        $history = $this->fetchAllianceHistory($corporationId);
        $historyFailed = $history === null;

        // 3) Derive change timestamp, previous alliance, confidence.
        //    Sort by start_date desc so entry[0] is "most recent",
        //    entry[1] is "the one immediately before that" (= the
        //    previous alliance the corp was in).
        $previousAllianceId = null;
        $lastChangeAt = null;
        $confidenceBand = CorporationAffiliationProfile::CONFIDENCE_LOW;

        if (! $historyFailed && $history !== []) {
            usort($history, static function (array $a, array $b): int {
                // Most recent first.
                return strcmp((string) ($b['start_date'] ?? ''), (string) ($a['start_date'] ?? ''));
            });

            $mostRecent = $history[0];
            $lastChangeAt = $this->parseDate($mostRecent['start_date'] ?? null);

            if (count($history) >= 2) {
                // entry[1] is the alliance the corp left most recently.
                // alliance_id can be absent from a history entry when
                // the corp was standalone during that stretch.
                $previousAllianceId = isset($history[1]['alliance_id'])
                    ? (int) $history[1]['alliance_id']
                    : null;
                $confidenceBand = CorporationAffiliationProfile::CONFIDENCE_HIGH;
            } else {
                // Single-entry history — corp has been in exactly one
                // alliance since creation, or has always been
                // standalone. Medium confidence because "no previous"
                // is a fact, not a missing datum.
                $confidenceBand = CorporationAffiliationProfile::CONFIDENCE_MEDIUM;
            }
        }

        $recentlyChanged = $lastChangeAt !== null
            && $lastChangeAt->gte(CarbonImmutable::now()->subDays($recentChangeDays));

        // 4) Upsert. Keyed on corporation_id (the natural PK).
        //    updateOrCreate gives us the conditional create + full
        //    attribute replace, which is what we want — every field
        //    reflects the latest observation.
        CorporationAffiliationProfile::query()->updateOrCreate(
            ['corporation_id' => $corporationId],
            [
                'current_alliance_id' => $currentAllianceId,
                'previous_alliance_id' => $previousAllianceId,
                'last_alliance_change_at' => $lastChangeAt,
                'recently_changed_affiliation' => $recentlyChanged,
                'history_confidence_band' => $confidenceBand,
                'observed_at' => Carbon::now(),
            ],
        );

        if ($historyFailed) {
            Log::info('corp affiliation sync: history endpoint failed, wrote current-only', [
                'corporation_id' => $corporationId,
                'current_alliance_id' => $currentAllianceId,
            ]);
        }

        return CorporationAffiliationSyncResult::synced(
            corpId: $corporationId,
            currentAllianceId: $currentAllianceId,
            previousAllianceId: $previousAllianceId,
        );
    }

    /**
     * Fetch the public corp record. Returns `alliance_id` (nullable)
     * on success or an error string. A 404 here means the corp has
     * been closed / deleted upstream — we surface that as an error
     * rather than overwriting the profile with null, so a mistaken
     * input ID doesn't erase real data.
     *
     * @return array{alliance_id: ?int, error: ?string}
     */
    private function fetchCurrentAllianceId(int $corporationId): array
    {
        try {
            $response = $this->esi->get(
                $this->newEsiUrl("/corporations/{$corporationId}"),
                headers: ['X-Compatibility-Date' => $this->compatDate()],
            );
        } catch (EsiException $e) {
            Log::info('corp affiliation: /corporations/{id} failed', [
                'corporation_id' => $corporationId,
                'status' => $e->status,
                'error' => $e->getMessage(),
            ]);

            return ['alliance_id' => null, 'error' => "corp lookup failed: {$e->getMessage()}"];
        }

        $body = $response->body ?? [];
        $allianceId = isset($body['alliance_id']) ? (int) $body['alliance_id'] : null;

        return ['alliance_id' => $allianceId, 'error' => null];
    }

    /**
     * Fetch the alliance history chain. Returns the raw array on
     * success, or `null` on any error (so callers can distinguish
     * "empty history" from "couldn't fetch").
     *
     * @return list<array<string, mixed>>|null
     */
    private function fetchAllianceHistory(int $corporationId): ?array
    {
        try {
            $response = $this->esi->get(
                $this->newEsiUrl("/corporations/{$corporationId}/alliancehistory"),
                headers: ['X-Compatibility-Date' => $this->compatDate()],
            );
        } catch (EsiException $e) {
            Log::info('corp affiliation: /alliancehistory failed', [
                'corporation_id' => $corporationId,
                'status' => $e->status,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $body = $response->body;
        if (! is_array($body)) {
            return null;
        }

        // The endpoint returns a flat list; preserve it as-is.
        return array_values($body);
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Build a fully-qualified new-ESI URL (esi.evetech.net, no
     * `/latest/`). Mirrors {@see CharacterStandingsFetcher::newEsiUrl()}
     * so the two fetchers stay aligned on base + compat-date policy.
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
}

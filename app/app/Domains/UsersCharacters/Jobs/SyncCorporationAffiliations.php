<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Jobs;

use App\Domains\UsersCharacters\Models\CharacterStanding;
use App\Domains\UsersCharacters\Models\CoalitionEntityLabel;
use App\Domains\UsersCharacters\Models\CorporationAffiliationProfile;
use App\Domains\UsersCharacters\Services\CorporationAffiliationFetcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sweep-fetch corporation affiliation profiles from ESI for every
 * corporation the resolver might target.
 *
 * What this populates:
 *
 *   - `corporation_affiliation_profiles.current_alliance_id`    — step 5 of the resolver
 *   - `corporation_affiliation_profiles.previous_alliance_id`   — step 6 (history)
 *   - `last_alliance_change_at`, `recently_changed_affiliation`, `history_confidence_band`
 *
 * Without these rows the resolver silently skips steps 5 and 6 and
 * falls straight through to the fallback — so keeping this sweep
 * healthy is what lets alliance-inheritance and history-derived
 * inference actually fire.
 *
 * Which corps get synced:
 *
 *   The union of "corps any viewer could meaningfully classify":
 *
 *     1. contact_id in character_standings where contact_type = 'corporation'
 *     2. characters.corporation_id (all linked characters)
 *     3. coalition_entity_labels.entity_id where entity_type = 'corporation'
 *     4. viewer_contexts.viewer_corporation_id
 *
 *   Filtered to those with no profile row, OR a profile row whose
 *   `observed_at` is older than the staleness threshold (default 24h).
 *
 *   We deliberately do NOT sync every corp in New Eden. Affiliation is
 *   public data (no scope, no cost per call beyond rate-limit budget),
 *   but there are hundreds of thousands of corps and only a tiny
 *   fraction ever show up in any classification. Needs-based pulls
 *   keep the sweep bounded.
 *
 * Cadence: daily. Corp alliance moves are measured in days between
 * shifts; a daily sweep picks up the signal within one business-EVE
 * cycle. Same placement pattern as {@see SyncDonorStandings}.
 *
 * Failure isolation: per-corp try/catch inside the loop. A corp that
 * 404s, 500s, or hits a rate-limit backoff doesn't take out the rest.
 */
class SyncCorporationAffiliations implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** One try. The daily cadence IS the retry policy. */
    public int $tries = 1;

    /**
     * Generous upper bound. A realistic sweep is O(100s) of corps at
     * ~300 ms each counting ETag-respected cache hits; 20 minutes is
     * comfortable headroom if ESI is degraded and we hit one-second
     * retries across the board.
     */
    public int $timeout = 1200;

    public function handle(CorporationAffiliationFetcher $fetcher): void
    {
        $stalenessHours = (int) config('classification.corp_affiliation_staleness_hours', 24);
        $recentChangeDays = (int) config('classification.recent_change_days', 14);

        $corpIds = $this->corpIdsToSync($stalenessHours);

        if ($corpIds === []) {
            Log::debug('corp affiliation sweep: nothing to sync');

            return;
        }

        $synced = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($corpIds as $corpId) {
            try {
                $result = $fetcher->sync($corpId, $recentChangeDays);

                if ($result->isSynced()) {
                    $synced++;
                } elseif ($result->status === \App\Domains\UsersCharacters\Services\CorporationAffiliationSyncResult::STATUS_SKIPPED) {
                    $skipped++;
                } else {
                    $failed++;
                    Log::info('corp affiliation sync failed', [
                        'corporation_id' => $corpId,
                        'message' => $result->message,
                    ]);
                }
            } catch (Throwable $e) {
                $failed++;
                Log::warning('corp affiliation sync: unexpected exception', [
                    'corporation_id' => $corpId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('corp affiliation sweep completed', [
            'synced' => $synced,
            'skipped' => $skipped,
            'failed' => $failed,
            'total_candidates' => count($corpIds),
            'staleness_hours' => $stalenessHours,
        ]);
    }

    /**
     * Union the four "interesting corps" sources, then filter to
     * those without a fresh profile. Returns a sorted unique list of
     * corporation IDs.
     *
     * @return list<int>
     */
    private function corpIdsToSync(int $stalenessHours): array
    {
        // 1) Corps appearing as contact in any standings row.
        $fromStandings = CharacterStanding::query()
            ->where('contact_type', CharacterStanding::CONTACT_CORPORATION)
            ->pluck('contact_id')
            ->all();

        // 2) Corps of every linked character.
        $fromCharacters = DB::table('characters')
            ->whereNotNull('corporation_id')
            ->pluck('corporation_id')
            ->all();

        // 3) Corps explicitly tagged in the coalition registry.
        $fromLabels = CoalitionEntityLabel::query()
            ->where('entity_type', CoalitionEntityLabel::ENTITY_CORPORATION)
            ->pluck('entity_id')
            ->all();

        // 4) Corps every viewer context holds cached.
        $fromViewers = DB::table('viewer_contexts')
            ->whereNotNull('viewer_corporation_id')
            ->pluck('viewer_corporation_id')
            ->all();

        $all = array_unique(array_map(
            'intval',
            array_merge($fromStandings, $fromCharacters, $fromLabels, $fromViewers),
        ));

        if ($all === []) {
            return [];
        }

        // Filter to corps whose profile is missing or stale. One
        // query against the profile table gets us both facts.
        $freshCutoff = Carbon::now()->subHours($stalenessHours);

        $freshCorpIds = CorporationAffiliationProfile::query()
            ->whereIn('corporation_id', $all)
            ->where('observed_at', '>=', $freshCutoff)
            ->pluck('corporation_id')
            ->all();

        $freshSet = array_flip(array_map('intval', $freshCorpIds));

        $toSync = [];
        foreach ($all as $corpId) {
            if (! isset($freshSet[$corpId])) {
                $toSync[] = $corpId;
            }
        }

        sort($toSync);

        return $toSync;
    }
}

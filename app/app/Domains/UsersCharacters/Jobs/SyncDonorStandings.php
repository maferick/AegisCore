<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Jobs;

use App\Domains\UsersCharacters\Models\EveMarketToken;
use App\Domains\UsersCharacters\Services\CharacterStandingsFetcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Walk every donor's market token and sync their corp + alliance
 * standings via {@see CharacterStandingsFetcher}.
 *
 * Cadence: daily. CCP caches the contacts endpoints for 5 minutes,
 * and corp/alliance standings shift on human timescales (hours to
 * days between edits), so a daily sweep keeps the table close enough
 * to live without burning ESI budget on rarely-changing data. The
 * on-demand "Sync standings now" button on /account/settings is
 * there for donors who want a freshness guarantee before a fleet op.
 *
 * Plane-boundary note (ADR-0002 § Job placement rule):
 *
 *   Per-donor sync is bounded — one token refresh + at most a few
 *   paginated contacts pages + one /universe/names/ batch. Typical
 *   per-donor walltime is a handful of seconds; a pathologically
 *   large alliance (thousands of contacts) could push higher. With
 *   dozens of donors this still fits inside a Horizon worker
 *   comfortably. If the donor count ever grows into the hundreds, we
 *   split each donor into its own dispatch — the fetcher is already
 *   per-token reentrant, so the change is a ->each() loop swap.
 *
 * Failure isolation:
 *
 *   One donor's sync failing (expired refresh, CCP degraded) MUST
 *   NOT take out the rest. Each sync is wrapped in its own try/catch
 *   and the error is logged with the failing character; the outer
 *   job continues.
 */
class SyncDonorStandings implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * One try. The daily cadence IS the retry policy — a failed
     * donor this run is either a transient outage (next run picks
     * up) or a hard auth issue (needs the donor to re-authorise on
     * /account/settings, which retrying this job can't fix).
     */
    public int $tries = 1;

    /**
     * Generous upper bound: dozens of donors × a few seconds each.
     * Guards against a catastrophic ESI stall pinning the worker.
     */
    public int $timeout = 600;

    public function handle(CharacterStandingsFetcher $fetcher): void
    {
        $tokens = EveMarketToken::query()->get();

        if ($tokens->isEmpty()) {
            Log::debug('eve:sync-standings skipped — no donor tokens registered');

            return;
        }

        $ok = 0;
        $failed = 0;
        foreach ($tokens as $token) {
            try {
                $result = $fetcher->sync($token);
                $ok++;
                Log::info('standings sync succeeded', [
                    'character_id' => $token->character_id,
                    'by_owner' => $result->byOwner(),
                ]);
            } catch (Throwable $e) {
                $failed++;
                Log::warning('standings sync failed for donor', [
                    'character_id' => $token->character_id,
                    'user_id' => $token->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('eve:sync-standings completed', [
            'donors_synced' => $ok,
            'donors_failed' => $failed,
        ]);
    }
}

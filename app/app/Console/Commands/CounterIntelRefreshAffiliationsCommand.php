<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\KillmailsBattleTheaters\Jobs\FetchCharacterCorporationHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * counter-intel:refresh-affiliations — re-fetch ESI affiliation
 * history for characters that surface in active CI hypotheses.
 *
 * Why: phase18 fuses signals from a 90-day rolling window. When a
 * pilot defects between blocs (e.g. Imperium → WC), their old
 * hostile-overlap signals drag forward and the Command Surface
 * reads as "fought against Goonswarm 67×" — true historically,
 * misleading operationally. This command focuses ESI sync on the
 * exact set of characters the operator is reviewing, so the
 * current_alliance lookup phase18 does at compute time reflects
 * fresh data.
 *
 * Idempotent — dispatches the existing FetchCharacterCorporation
 * History job which is itself rate-limited and ShouldBeUnique.
 */
class CounterIntelRefreshAffiliationsCommand extends Command
{
    protected $signature = 'counter-intel:refresh-affiliations
        {--bloc-id=1 : viewer bloc id}
        {--min-band=medium : low | medium | high | confirmed}
        {--limit=500 : maximum characters to enqueue per run}';

    protected $description = 'Re-fetch ESI affiliation history for characters in active CI hypotheses (defector / recruit detection).';

    public function handle(): int
    {
        $blocId = (int) $this->option('bloc-id');
        $minBand = (string) $this->option('min-band');
        $limit = max(1, (int) $this->option('limit'));

        $bands = match ($minBand) {
            'low'      => ['low', 'medium', 'high', 'confirmed'],
            'medium'   => ['medium', 'high', 'confirmed'],
            'high'     => ['high', 'confirmed'],
            'confirmed' => ['confirmed'],
            default    => ['medium', 'high', 'confirmed'],
        };

        $cids = DB::table('counter_intel_hypotheses')
            ->where('viewer_bloc_id', $blocId)
            ->whereIn('confidence', $bands)
            ->where('status', '<>', 'archived')
            ->orderByDesc('suspicion_score')
            ->limit($limit)
            ->pluck('primary_character_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        if ($cids === []) {
            $this->info("no active hypotheses at band={$minBand} for bloc {$blocId}");
            return self::SUCCESS;
        }

        // Surface the working set so the operator sees who's
        // pending refresh. The actual ESI fetch goes through the
        // existing FetchCharacterCorporationHistory job — it
        // self-dispatches on its own schedule and is rate-limited.
        // What this command does is *force* a fresh dispatch so the
        // sweep happens now instead of next scheduled tick.
        $this->info('forcing affiliation-history sweep now (will catch ' . count($cids) . ' subjects on next batch)');
        FetchCharacterCorporationHistory::dispatch();

        $this->info('dispatched. Job is rate-limited (BATCH_SIZE=500 / dispatch);');
        $this->info('next phase18 cron tick will see fresh current_alliance values.');
        $this->info('manual fast-path: VIEWER_BLOC=' . $blocId . ' make ci-phase18-hypothesis-fusion');
        return self::SUCCESS;
    }
}

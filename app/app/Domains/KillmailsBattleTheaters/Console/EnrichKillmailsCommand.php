<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Console;

use App\Domains\KillmailsBattleTheaters\Jobs\EnrichPendingKillmails;
use App\Domains\KillmailsBattleTheaters\Models\Killmail;
use Illuminate\Console\Command;

/**
 * Entry point for the killmail enrichment backlog processor.
 *
 * Dispatches one enrichment job per unenriched month so they run in
 * parallel across Horizon's worker pool. Each month self-dispatches
 * until its slice is fully enriched.
 */
class EnrichKillmailsCommand extends Command
{
    protected $signature = 'killmails:enrich
        {--sync : Run one batch inline instead of dispatching to Horizon}';

    protected $description = 'Enrich pending killmails — dispatches parallel jobs per month';

    public function handle(): int
    {
        $pending = Killmail::unenriched()->count();

        if ($pending === 0) {
            $this->info('No unenriched killmails.');

            return self::SUCCESS;
        }

        $this->info("Unenriched killmails: ".number_format($pending));

        if ($this->option('sync')) {
            $this->info('Running one batch inline...');
            $job = new EnrichPendingKillmails;
            $job->handle(app(\App\Domains\KillmailsBattleTheaters\Actions\EnrichKillmail::class));

            return self::SUCCESS;
        }

        $months = EnrichPendingKillmails::dispatchAllMonths();
        $this->info("Dispatched {$months} month-partitioned enrichment jobs to Horizon.");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Console;

use App\Domains\KillmailsBattleTheaters\Jobs\EnrichPendingKillmails;
use Illuminate\Console\Command;

/**
 * Entry point for the killmail enrichment backlog processor.
 *
 * Usage:
 *   php artisan killmails:enrich            # dispatches onto Horizon
 *   php artisan killmails:enrich --sync     # runs inline (debugging)
 *
 * The job self-dispatches when more unenriched killmails remain, so a
 * single dispatch drains the entire backlog. The scheduler kicks it
 * every minute as a safety net in case the self-dispatch chain breaks.
 */
class EnrichKillmailsCommand extends Command
{
    protected $signature = 'killmails:enrich
        {--sync : Run inline instead of dispatching to Horizon}';

    protected $description = 'Enrich pending killmails (valuation + classification + names)';

    public function handle(): int
    {
        $pending = \App\Domains\KillmailsBattleTheaters\Models\Killmail::unenriched()->count();

        if ($pending === 0) {
            $this->info('No unenriched killmails.');

            return self::SUCCESS;
        }

        $this->info("Unenriched killmails: {$pending}");

        if ($this->option('sync')) {
            $this->info('Running inline...');
            $job = new EnrichPendingKillmails;
            $job->handle(app(\App\Domains\KillmailsBattleTheaters\Actions\EnrichKillmail::class));

            return self::SUCCESS;
        }

        EnrichPendingKillmails::dispatch();
        $this->info('Dispatched EnrichPendingKillmails to Horizon.');

        return self::SUCCESS;
    }
}

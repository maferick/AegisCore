<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Console;

use App\Domains\UsersCharacters\Jobs\SyncDonorStandings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Entry point for the daily corp+alliance standings sync sweep.
 *
 * Usage:
 *   php artisan eve:sync-standings            # dispatch onto Horizon
 *   php artisan eve:sync-standings --sync     # run inline (debugging)
 *
 * Scheduled from `routes/console.php`. Mirrors the shape of
 * {@see \App\Domains\UsersCharacters\Console\PollDonationsCommand}
 * so the operator surface is consistent across EVE-integration
 * polling commands.
 */
class SyncStandingsCommand extends Command
{
    protected $signature = 'eve:sync-standings
                            {--sync : Run inline instead of dispatching to Horizon}';

    protected $description = 'Sync corp + alliance standings from ESI for every donor with a market token.';

    public function handle(): int
    {
        if ($this->option('sync')) {
            Bus::dispatchSync(new SyncDonorStandings);
            $this->info('Standings sync completed.');

            return self::SUCCESS;
        }

        SyncDonorStandings::dispatch();
        $this->info('Dispatched SyncDonorStandings onto Horizon.');

        return self::SUCCESS;
    }
}

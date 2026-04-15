<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Console;

use App\Domains\UsersCharacters\Jobs\SyncCorporationAffiliations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Entry point for the daily corporation affiliation sweep.
 *
 * Usage:
 *   php artisan classification:sync-corp-affiliations          # dispatch onto Horizon
 *   php artisan classification:sync-corp-affiliations --sync   # run inline (debugging)
 *
 * Scheduled from `routes/console.php`. Mirrors the shape of
 * {@see SyncStandingsCommand} so the operator surface stays uniform
 * across the classification-system polling commands.
 */
class SyncCorporationAffiliationsCommand extends Command
{
    protected $signature = 'classification:sync-corp-affiliations
                            {--sync : Run inline instead of dispatching to Horizon}';

    protected $description = 'Sync corporation alliance + history profiles from ESI for the classification resolver.';

    public function handle(): int
    {
        if ($this->option('sync')) {
            Bus::dispatchSync(new SyncCorporationAffiliations);
            $this->info('Corporation affiliation sweep completed.');

            return self::SUCCESS;
        }

        SyncCorporationAffiliations::dispatch();
        $this->info('Dispatched SyncCorporationAffiliations onto Horizon.');

        return self::SUCCESS;
    }
}

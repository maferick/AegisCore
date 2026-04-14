<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Console;

use App\Domains\UsersCharacters\Jobs\PollDonationsWallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Entry point for the donations-character wallet poller.
 *
 * Usage:
 *   php artisan eve:poll-donations            # dispatches onto Horizon
 *   php artisan eve:poll-donations --sync     # runs inline (debugging)
 *
 * Scheduled every 5 minutes from `routes/console.php` (cadence comes
 * from `EVE_DONATIONS_POLL_CRON`, default `*\/5 * * * *`). Dispatching
 * to Horizon (default) is the production path: keeps the scheduler
 * process unblocked and gives us the per-job retry / failure surface.
 *
 * Inline (`--sync`) is for `make donations-poll` and ad-hoc operator
 * runs where seeing the result on stdout is more useful than tailing
 * the queue.
 *
 * See {@see PollDonationsWallet} for the actual polling logic and the
 * plane-boundary reasoning (ADR-0002 § phase-2 amendment).
 */
class PollDonationsCommand extends Command
{
    protected $signature = 'eve:poll-donations
                            {--sync : Run inline instead of dispatching to Horizon}';

    protected $description = 'Pull new player_donation rows from the donations character\'s wallet journal.';

    public function handle(): int
    {
        if ($this->option('sync')) {
            Bus::dispatchSync(new PollDonationsWallet);
            $this->info('Donations wallet poll completed.');

            return self::SUCCESS;
        }

        PollDonationsWallet::dispatch();
        $this->info('Dispatched PollDonationsWallet onto Horizon.');

        return self::SUCCESS;
    }
}

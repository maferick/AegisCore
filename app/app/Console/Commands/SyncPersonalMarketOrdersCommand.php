<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\UsersCharacters\Jobs\SyncPersonalMarketOrders;
use Illuminate\Console\Command;

/**
 * On-demand trigger for the SyncPersonalMarketOrders job — useful
 * for ops debugging ("sync my alt's orders now") and as the command
 * the scheduler dispatches hourly.
 */
class SyncPersonalMarketOrdersCommand extends Command
{
    protected $signature = 'eve:sync-personal-orders';

    protected $description = 'Sync personal market orders + history for every market-tokened character.';

    public function handle(): int
    {
        $this->info('Dispatching SyncPersonalMarketOrders...');
        SyncPersonalMarketOrders::dispatchSync();
        $this->info('Done.');
        return self::SUCCESS;
    }
}

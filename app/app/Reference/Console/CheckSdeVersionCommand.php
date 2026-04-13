<?php

declare(strict_types=1);

namespace App\Reference\Console;

use App\Reference\Jobs\CheckSdeVersion;
use App\Reference\Models\SdeVersionCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Entry point for the daily SDE version-drift check.
 *
 * Usage:
 *   php artisan reference:check-sde-version            # dispatches onto Horizon
 *   php artisan reference:check-sde-version --sync     # runs inline, prints result
 *
 * Scheduled from routes/console.php; exposed to operators via `make sde-check`.
 */
class CheckSdeVersionCommand extends Command
{
    protected $signature = 'reference:check-sde-version
                            {--sync : Run inline instead of dispatching to the queue}';

    protected $description = 'HEAD the pinned SDE tarball URL, record drift vs. upstream.';

    public function handle(): int
    {
        if ($this->option('sync')) {
            // Inline path — useful for `make sde-check` so the operator
            // gets immediate feedback without tailing Horizon logs.
            Bus::dispatchSync(new CheckSdeVersion);

            $latest = SdeVersionCheck::query()->latest()->first();
            if ($latest === null) {
                $this->error('Check ran but no row was written — inspect logs.');

                return self::FAILURE;
            }

            $this->renderResult($latest);

            return self::SUCCESS;
        }

        CheckSdeVersion::dispatch();
        $this->info('Dispatched CheckSdeVersion onto Horizon.');

        return self::SUCCESS;
    }

    private function renderResult(SdeVersionCheck $check): void
    {
        $this->line('SDE version check result:');
        $this->line('  pinned   : '.($check->pinned_version ?? '(none)'));
        $this->line('  upstream : '.($check->upstream_version ?? '(unknown)'));
        $this->line('  bump     : '.($check->is_bump_available ? 'YES' : 'no'));

        if ($check->notes !== null) {
            $this->warn('  notes    : '.$check->notes);
        }
    }
}

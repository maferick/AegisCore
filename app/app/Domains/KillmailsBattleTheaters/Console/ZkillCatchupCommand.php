<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Console;

use App\Domains\KillmailsBattleTheaters\Jobs\ZkillSystemCatchupJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Dispatch a `ZkillSystemCatchupJob` for every solar system that had
 * killmail activity in the last `--window-hours` hours. Scheduler
 * calls this every 3h; operators can also run it manually.
 *
 * The jobs themselves hit zkill + ESI; this command just fans them
 * out into Horizon.
 */
final class ZkillCatchupCommand extends Command
{
    protected $signature = 'killmails:zkill-catchup
        {--window-hours=4 : Look at systems active in the last N hours}
        {--past-seconds=7200 : Per-system lookback passed to zkill (snapped to a 3600 multiple)}';

    protected $description = 'Ask zkill for any killmails we missed in recently-active systems and ingest them';

    public function handle(): int
    {
        $windowHours = max(1, (int) $this->option('window-hours'));
        $pastSeconds = max(3600, (int) $this->option('past-seconds'));

        $cutoff = now()->subHours($windowHours);

        $systemIds = DB::table('killmails')
            ->where('created_at', '>=', $cutoff)
            ->where('solar_system_id', '>', 0)
            ->selectRaw('solar_system_id, COUNT(*) as c')
            ->groupBy('solar_system_id')
            ->orderByDesc('c')
            ->pluck('solar_system_id')
            ->all();

        $this->info('Dispatching '.count($systemIds).' per-system catch-up jobs');

        foreach ($systemIds as $sysId) {
            ZkillSystemCatchupJob::dispatch((int) $sysId, $pastSeconds);
        }

        return self::SUCCESS;
    }
}

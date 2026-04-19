<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Eve\Esi\EsiClient;
use App\Services\Eve\Esi\EsiException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pull the current sovereignty map from ESI and mirror into
 * system_sovereignty. Public endpoint, no auth required, ETag cached
 * by EsiClient so reruns are cheap.
 */
class SyncSovereigntyCommand extends Command
{
    protected $signature = 'sov:sync {--force : Bypass ETag cache}';

    protected $description = 'Sync /sovereignty/map/ into system_sovereignty.';

    public function handle(EsiClient $esi): int
    {
        $this->info('Fetching /sovereignty/map/…');
        try {
            $resp = $esi->get('/sovereignty/map/', [], null, forceRefresh: (bool) $this->option('force'));
        } catch (EsiException $e) {
            $this->error("Fetch failed: {$e->getMessage()}");
            return 1;
        }
        $rows = $resp->body ?? [];
        if (! is_array($rows) || $rows === []) {
            $this->warn('Empty response (cache-304 or ESI dry?). Pass --force to bypass cache.');
            return 0;
        }
        $this->info(count($rows) . ' systems returned.');

        $now = now();
        $batch = [];
        foreach ($rows as $r) {
            $batch[] = [
                'solar_system_id' => (int) ($r['system_id'] ?? 0),
                'alliance_id' => isset($r['alliance_id']) ? (int) $r['alliance_id'] : null,
                'corporation_id' => isset($r['corporation_id']) ? (int) $r['corporation_id'] : null,
                'faction_id' => isset($r['faction_id']) ? (int) $r['faction_id'] : null,
                'fetched_at' => $now,
            ];
        }
        // TRUNCATE implicitly commits in MariaDB so we can't wrap in a
        // transaction. Brief window where the table is empty during
        // reload — acceptable; command is not hot-path.
        DB::statement('TRUNCATE TABLE system_sovereignty');
        foreach (array_chunk($batch, 1000) as $chunk) {
            DB::table('system_sovereignty')->insert($chunk);
        }
        $this->info('Sovereignty synced.');
        return 0;
    }
}

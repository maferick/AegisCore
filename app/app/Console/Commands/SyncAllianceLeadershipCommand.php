<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Eve\Esi\EsiClient;
use App\Services\Eve\Esi\EsiException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Populate alliance_leadership from ESI /alliances/{id}/.
 *
 * Covers every alliance that appears as an attacker or victim in
 * the last 90 days of killmails — the set our counter-intel +
 * alliance-lookup surfaces actually talk about. Public endpoint,
 * no token, cheap on the ESI side.
 */
class SyncAllianceLeadershipCommand extends Command
{
    protected $signature = 'alliance:sync-leadership {--limit=0 : Stop after N (0=no limit)}';

    protected $description = 'Fetch creator + executor corp for every recently-active alliance.';

    public function handle(EsiClient $esi): int
    {
        $limit = (int) $this->option('limit');
        $ids = $this->activeAllianceIds();
        if ($limit > 0) $ids = array_slice($ids, 0, $limit);
        $this->info(count($ids) . ' alliance(s) to refresh.');

        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();
        $written = 0;
        $failed = 0;
        foreach ($ids as $aid) {
            try {
                $resp = $esi->get("/alliances/{$aid}/");
            } catch (EsiException $e) {
                $failed++;
                $bar->advance();
                continue;
            }
            $body = $resp->body ?? null;
            if (! is_array($body)) { $bar->advance(); continue; }
            DB::table('alliance_leadership')->updateOrInsert(
                ['alliance_id' => $aid],
                [
                    'creator_character_id' => isset($body['creator_id']) ? (int) $body['creator_id'] : null,
                    'creator_corporation_id' => isset($body['creator_corporation_id']) ? (int) $body['creator_corporation_id'] : null,
                    'executor_corporation_id' => isset($body['executor_corporation_id']) ? (int) $body['executor_corporation_id'] : null,
                    'name' => isset($body['name']) ? (string) $body['name'] : null,
                    'ticker' => isset($body['ticker']) ? (string) $body['ticker'] : null,
                    'date_founded' => isset($body['date_founded']) ? date('Y-m-d H:i:s', strtotime((string) $body['date_founded'])) : null,
                    'last_fetched_at' => now(),
                ],
            );
            $written++;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Written: {$written} · Failed: {$failed}");
        return 0;
    }

    /** @return list<int> */
    private function activeAllianceIds(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT aid FROM (
              SELECT DISTINCT ka.alliance_id AS aid
                FROM killmail_attackers ka
                JOIN killmails k ON k.killmail_id = ka.killmail_id
               WHERE ka.alliance_id IS NOT NULL
                 AND k.killed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
              UNION
              SELECT DISTINCT k.victim_alliance_id AS aid
                FROM killmails k
               WHERE k.victim_alliance_id IS NOT NULL
                 AND k.killed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            ) u
        SQL);
        return array_map(fn ($r) => (int) $r->aid, $rows);
    }
}

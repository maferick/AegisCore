<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;

/**
 * Rebuild Voronoi catchments: every solar system mapped to its
 * nearest active market hub by stargate jumps.
 *
 * Uses Neo4j's :System graph (JUMPS_TO edges already projected by
 * graph_universe_sync) to BFS from each hub's system in parallel
 * and keeps the (system_id, hub_id, jumps) tuple with the lowest
 * jump count per system. Systems further than --max-jumps from
 * every hub stay out of the table so deep-roam kills don't get
 * spuriously bound to a distant hub.
 *
 * Idempotent: truncates + reinserts in one transaction so the
 * mapping never renders half-populated to consumers.
 */
class RebuildMarketHubCatchmentsCommand extends Command
{
    protected $signature = 'markets:rebuild-hub-catchments {--max-jumps=30}';

    protected $description = 'Rebuild market_hub_catchments (nearest active hub per system) via Neo4j BFS.';

    public function handle(): int
    {
        $maxJumps = max(1, (int) $this->option('max-jumps'));

        $hubs = DB::table('market_hubs')
            ->where('is_active', 1)
            ->whereNotNull('solar_system_id')
            ->get(['id', 'structure_name', 'solar_system_id']);
        if ($hubs->isEmpty()) {
            $this->warn('No active hubs with solar_system_id set.');
            return self::SUCCESS;
        }

        $uri = (string) env('NEO4J_BOLT_URI', 'bolt://neo4j:7687');
        $user = (string) env('NEO4J_USER', 'neo4j');
        $pw = (string) env('NEO4J_PASSWORD', '');
        $client = ClientBuilder::create()
            ->withDriver('n', $uri, Authenticate::basic($user, $pw))
            ->withDefaultDriver('n')
            ->build();

        // Per-hub BFS via apoc.path.expandConfig. nodeGlobal uniqueness
        // means each system appears at most once per expansion (shortest
        // path). Low hub count (≤ ~10) keeps total cost small even at
        // maxLevel=30.
        $nearest = [];   // system_id => ['hub_id' => int, 'jumps' => int]
        foreach ($hubs as $hub) {
            $systemId = (int) $hub->solar_system_id;
            $cypher = <<<'CYPHER'
                MATCH (src:System {id: $systemId})
                CALL apoc.path.expandConfig(src, {
                    relationshipFilter: 'JUMPS_TO',
                    minLevel: 0,
                    maxLevel: $maxJumps,
                    bfs: true,
                    uniqueness: 'NODE_GLOBAL'
                }) YIELD path
                WITH last(nodes(path)) AS dst, length(path) AS jumps
                RETURN dst.id AS system_id, jumps
                CYPHER;
            $rows = $client->run($cypher, ['systemId' => $systemId, 'maxJumps' => $maxJumps]);
            $reached = 0;
            foreach ($rows as $r) {
                $sid = (int) $r->get('system_id');
                $j = (int) $r->get('jumps');
                $existing = $nearest[$sid] ?? null;
                if ($existing === null
                    || $j < $existing['jumps']
                    || ($j === $existing['jumps'] && $hub->id < $existing['hub_id'])) {
                    $nearest[$sid] = ['hub_id' => (int) $hub->id, 'jumps' => $j];
                }
                $reached++;
            }
            $this->info("hub {$hub->id} ({$hub->structure_name}): expanded to {$reached} systems.");
        }

        if ($nearest === []) {
            $this->warn('No systems reached. Aborting rebuild.');
            return self::SUCCESS;
        }

        // Truncate + bulk insert inside one transaction so a killmail
        // lookup never sees half a mapping during refresh.
        DB::transaction(function () use ($nearest) {
            // DELETE (not TRUNCATE) — TRUNCATE auto-commits in InnoDB
            // and would break the enclosing transaction.
            DB::table('market_hub_catchments')->delete();
            $batch = [];
            $now = now();
            foreach ($nearest as $sid => $v) {
                $batch[] = [
                    'solar_system_id' => $sid,
                    'hub_id' => $v['hub_id'],
                    'jumps' => $v['jumps'],
                    'computed_at' => $now,
                ];
                if (count($batch) >= 1000) {
                    DB::table('market_hub_catchments')->insert($batch);
                    $batch = [];
                }
            }
            if ($batch) DB::table('market_hub_catchments')->insert($batch);
        });

        $counts = DB::table('market_hub_catchments')
            ->selectRaw('hub_id, COUNT(*) AS n')
            ->groupBy('hub_id')
            ->pluck('n', 'hub_id');
        foreach ($counts as $hid => $n) {
            $this->info("hub {$hid}: owns {$n} systems");
        }

        return self::SUCCESS;
    }
}

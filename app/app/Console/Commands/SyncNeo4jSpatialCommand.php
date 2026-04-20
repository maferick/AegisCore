<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;

/**
 * Project spatial fleet-movement graph: ansiblex jump bridges +
 * titan-bridge range pairs + sovereignty ownership.
 *
 * Phase B4 of the find-spy plan. Unlocks path queries like:
 *   * "shortest ansiblex path from pilot's home system to
 *     hostile-bloc sov"
 *   * "systems within titan-bridge range of N hostile staging"
 *   * "pilots home-docked in hostile-sov systems"
 *
 * All edges attach to :System nodes already present from
 * graph_universe_sync. Sov + home-system markers land as System
 * node properties.
 */
class SyncNeo4jSpatialCommand extends Command
{
    protected $signature = 'neo4j:sync-spatial';

    protected $description = 'Project ansiblex + titan-range + sov edges onto :System nodes.';

    public function handle(): int
    {
        $uri = (string) env('NEO4J_BOLT_URI', 'bolt://neo4j:7687');
        $user = (string) env('NEO4J_USER', 'neo4j');
        $pw = (string) env('NEO4J_PASSWORD', '');

        $dbCfg = config('database.connections.' . config('database.default'));
        $mariaUser = (string) ($dbCfg['username'] ?? 'aegiscore');
        $mariaPw = (string) ($dbCfg['password'] ?? '');
        $mariaDb = (string) ($dbCfg['database'] ?? 'aegiscore');
        $jdbc = sprintf(
            'jdbc:mariadb://mariadb:3306/%s?user=%s&password=%s',
            $mariaDb, $mariaUser, rawurlencode($mariaPw),
        );

        $client = ClientBuilder::create()
            ->withDriver('n', $uri, Authenticate::basic($user, $pw))
            ->withDefaultDriver('n')
            ->build();

        // ANSIBLEX_TO edges — one per bridge, bidirectional in real
        // life but we emit both directions explicitly so Cypher-side
        // pattern matching doesn't have to use undirected edges.
        $cypher = sprintf(<<<'CYPHER'
            CALL apoc.periodic.iterate(
              "CALL apoc.load.jdbc('%s',
                 'SELECT from_system_id, to_system_id, alliance_id, name FROM ansiblex_jump_bridges'
              ) YIELD row RETURN row",
              'MATCH (a:System {id: toInteger(row.from_system_id)})
               MATCH (b:System {id: toInteger(row.to_system_id)})
               MERGE (a)-[r:ANSIBLEX_TO]->(b)
               SET r.alliance_id = toInteger(row.alliance_id),
                   r.name = row.name
               MERGE (b)-[r2:ANSIBLEX_TO]->(a)
               SET r2.alliance_id = toInteger(row.alliance_id),
                   r2.name = row.name',
              {batchSize: 500, parallel: false}
            ) YIELD batches, total, committedOperations
            RETURN batches, total, committedOperations
            CYPHER, $jdbc);
        foreach ($client->run($cypher) as $row) {
            $this->info(sprintf('ANSIBLEX_TO: batches=%d total=%d committed=%d',
                $row->get('batches'), $row->get('total'), $row->get('committedOperations')));
        }

        // TITAN_RANGE edges — 6 LY pairs. 372K → heavy; batched via
        // apoc.periodic.iterate at 10k batchSize. Directionless in
        // real life; project one direction only + leave symmetry for
        // Cypher to handle via undirected MATCH.
        $cypher = sprintf(<<<'CYPHER'
            CALL apoc.periodic.iterate(
              "CALL apoc.load.jdbc('%s',
                 'SELECT from_system_id, to_system_id, ly_distance
                    FROM system_titan_bridges
                   WHERE from_system_id < to_system_id'
              ) YIELD row RETURN row",
              'MATCH (a:System {id: toInteger(row.from_system_id)})
               MATCH (b:System {id: toInteger(row.to_system_id)})
               MERGE (a)-[r:TITAN_RANGE]->(b)
               SET r.ly_distance = toFloat(row.ly_distance)',
              {batchSize: 10000, parallel: false}
            ) YIELD batches, total, committedOperations
            RETURN batches, total, committedOperations
            CYPHER, $jdbc);
        foreach ($client->run($cypher) as $row) {
            $this->info(sprintf('TITAN_RANGE: batches=%d total=%d committed=%d',
                $row->get('batches'), $row->get('total'), $row->get('committedOperations')));
        }

        // Sov ownership — set System props + HOLDS_SOV edge from
        // Alliance side (if alliance_id set, else skip).
        $cypher = sprintf(<<<'CYPHER'
            CALL apoc.periodic.iterate(
              "CALL apoc.load.jdbc('%s',
                 'SELECT solar_system_id, alliance_id, corporation_id, faction_id FROM system_sovereignty'
              ) YIELD row RETURN row",
              'MATCH (s:System {id: toInteger(row.solar_system_id)})
               SET s.sov_alliance_id = toInteger(row.alliance_id),
                   s.sov_corporation_id = toInteger(row.corporation_id),
                   s.sov_faction_id = toInteger(row.faction_id)
               WITH s, row WHERE row.alliance_id IS NOT NULL
               MATCH (a:Alliance {alliance_id: toInteger(row.alliance_id)})
               MERGE (a)-[:HOLDS_SOV]->(s)',
              {batchSize: 2000, parallel: false}
            ) YIELD batches, total, committedOperations
            RETURN batches, total, committedOperations
            CYPHER, $jdbc);
        foreach ($client->run($cypher) as $row) {
            $this->info(sprintf('sov + HOLDS_SOV: batches=%d total=%d committed=%d',
                $row->get('batches'), $row->get('total'), $row->get('committedOperations')));
        }

        $client->run('CREATE INDEX system_sov_alliance IF NOT EXISTS FOR (s:System) ON (s.sov_alliance_id)');

        return self::SUCCESS;
    }
}

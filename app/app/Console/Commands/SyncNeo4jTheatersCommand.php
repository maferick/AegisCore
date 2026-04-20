<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;

/**
 * Project significant battle theaters + per-character participation
 * edges into Neo4j via APOC JDBC.
 *
 * Phase A2 of the find-spy plan. Enables co-occurrence / opposed-
 * side queries like:
 *   "pilots who FOUGHT_IN ≥ 3 theaters where a Fraternity alliance
 *    also participated but on the opposite side"
 *   "pilots in my bloc who fought_in theaters with anomaly-band-high
 *    peers"
 *
 * Scope — only project theaters that:
 *   1. are locked (stable clustering, scheduler won't delete them);
 *   2. have ≥ 20 participants (align with list view threshold);
 *   3. have end_time >= now() - 180 days (recent intel).
 *
 * Emits:
 *   * (t:Theater {id, primary_system_id, start_time, end_time,
 *                 region_id, participant_count})
 *   * (p:CICharacter)-[:FOUGHT_IN {alliance_id}]->(t:Theater)
 *
 * The alliance_id property on the edge carries the pilot's
 * alliance at the time of the theater, so side resolution can be
 * redone downstream by joining with Alliance nodes' IN_BLOC edges.
 * Idempotent via MERGE.
 */
class SyncNeo4jTheatersCommand extends Command
{
    protected $signature = 'neo4j:sync-theaters {--days=180}';

    protected $description = 'Project significant battle theaters + FOUGHT_IN edges into Neo4j.';

    public function handle(): int
    {
        $days = (int) $this->option('days');

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

        $client->run('CREATE CONSTRAINT theater_id_uniq IF NOT EXISTS FOR (t:Theater) REQUIRE t.id IS UNIQUE');

        // Pull filtered theater set once — count, log, project.
        $this->info(sprintf('Projecting theaters locked + ≥ 20 pilots + last %d d', $days));

        $cypher = sprintf(<<<'CYPHER'
            CALL apoc.periodic.iterate(
              "CALL apoc.load.jdbc('%s',
                 'SELECT id, primary_system_id, region_id, participant_count,
                         DATE_FORMAT(start_time, \"%%Y-%%m-%%dT%%H:%%i:%%s\") AS start_time,
                         DATE_FORMAT(end_time, \"%%Y-%%m-%%dT%%H:%%i:%%s\") AS end_time
                    FROM battle_theaters
                   WHERE locked_at IS NOT NULL
                     AND participant_count >= 20
                     AND end_time >= DATE_SUB(NOW(), INTERVAL %d DAY)'
              ) YIELD row RETURN row",
              'MERGE (t:Theater {id: toInteger(row.id)})
               SET t.primary_system_id = toInteger(row.primary_system_id),
                   t.region_id = toInteger(row.region_id),
                   t.participant_count = toInteger(row.participant_count),
                   t.start_time = row.start_time,
                   t.end_time = row.end_time',
              {batchSize: 2000, parallel: false}
            ) YIELD batches, total, committedOperations
            RETURN batches, total, committedOperations
            CYPHER, $jdbc, $days);
        foreach ($client->run($cypher) as $row) {
            $this->info(sprintf('Theaters: batches=%d total=%d committed=%d',
                $row->get('batches'), $row->get('total'), $row->get('committedOperations')));
        }

        // FOUGHT_IN edges — one edge per (character_id, theater_id).
        // Filter JOIN on the same theater scope to avoid materialising
        // edges for theaters we didn't project.
        $cypher = sprintf(<<<'CYPHER'
            CALL apoc.periodic.iterate(
              "CALL apoc.load.jdbc('%s',
                 'SELECT p.character_id, p.theater_id, p.alliance_id
                    FROM battle_theater_participants p
                    JOIN battle_theaters bt ON bt.id = p.theater_id
                   WHERE bt.locked_at IS NOT NULL
                     AND bt.participant_count >= 20
                     AND bt.end_time >= DATE_SUB(NOW(), INTERVAL %d DAY)
                     AND p.character_id IS NOT NULL'
              ) YIELD row RETURN row",
              'MATCH (c:CICharacter {character_id: toInteger(row.character_id)})
               MATCH (t:Theater {id: toInteger(row.theater_id)})
               MERGE (c)-[r:FOUGHT_IN]->(t)
               SET r.alliance_id = toInteger(row.alliance_id)',
              {batchSize: 5000, parallel: false}
            ) YIELD batches, total, committedOperations
            RETURN batches, total, committedOperations
            CYPHER, $jdbc, $days);
        foreach ($client->run($cypher) as $row) {
            $this->info(sprintf('FOUGHT_IN: batches=%d total=%d committed=%d',
                $row->get('batches'), $row->get('total'), $row->get('committedOperations')));
        }

        // Attach the theater to its primary :System (universe graph
        // already has System nodes from graph_universe_sync).
        $cypher = sprintf(<<<'CYPHER'
            CALL apoc.periodic.iterate(
              'MATCH (t:Theater) WHERE t.primary_system_id IS NOT NULL RETURN t',
              'MATCH (s:System {id: t.primary_system_id}) MERGE (t)-[:IN_SYSTEM]->(s)',
              {batchSize: 2000, parallel: false}
            ) YIELD batches, total, committedOperations
            RETURN batches, total, committedOperations
            CYPHER);
        foreach ($client->run($cypher) as $row) {
            $this->info(sprintf('IN_SYSTEM: batches=%d total=%d committed=%d',
                $row->get('batches'), $row->get('total'), $row->get('committedOperations')));
        }

        $client->run('CREATE INDEX theater_end IF NOT EXISTS FOR (t:Theater) ON (t.end_time)');
        $client->run('CREATE INDEX theater_system IF NOT EXISTS FOR (t:Theater) ON (t.primary_system_id)');

        return self::SUCCESS;
    }
}

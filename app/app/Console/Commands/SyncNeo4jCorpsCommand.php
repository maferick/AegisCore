<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;

/**
 * Sync :Corporation nodes + (:Corporation)-[:IN_ALLIANCE]->(:Alliance)
 * and (:CICharacter)-[:MEMBER_OF_CORP]->(:Corporation) edges from
 * MariaDB into Neo4j via APOC load.jdbc + periodic.iterate.
 *
 * Phase 0.2 from the find-spy plan. Requires the MariaDB JDBC driver
 * (mariadb-java-client.jar) present in the Neo4j /plugins directory.
 *
 * Scope:
 *   * All corporations known to esi_entity_names (~65k).
 *   * Current alliance per corp (end_date IS NULL) and current corp
 *     per character (end_date IS NULL, is_deleted = 0). Historical
 *     edges are a Phase D follow-up.
 *
 * Idempotent — MERGE on both sides. Re-run nightly.
 */
class SyncNeo4jCorpsCommand extends Command
{
    protected $signature = 'neo4j:sync-corps';

    protected $description = 'Project corporations + corp/char/alliance edges into Neo4j (APOC JDBC).';

    public function handle(): int
    {
        $uri = (string) env('NEO4J_BOLT_URI', 'bolt://neo4j:7687');
        $user = (string) env('NEO4J_USER', 'neo4j');
        $pw = (string) env('NEO4J_PASSWORD', '');

        // Neo4j calls MariaDB through its JDBC driver; the MARIADB_*
        // vars aren't exposed to php-fpm (only DB_*). Read the Laravel
        // connection config instead so credentials match what's used
        // elsewhere.
        $dbCfg = config('database.connections.' . config('database.default'));
        $mariaUser = (string) ($dbCfg['username'] ?? 'aegiscore');
        $mariaPw = (string) ($dbCfg['password'] ?? '');
        $mariaDb = (string) ($dbCfg['database'] ?? 'aegiscore');
        $jdbcUrl = sprintf(
            'jdbc:mariadb://mariadb:3306/%s?user=%s&password=%s',
            $mariaDb, $mariaUser, rawurlencode($mariaPw),
        );

        $client = ClientBuilder::create()
            ->withDriver('n', $uri, Authenticate::basic($user, $pw))
            ->withDefaultDriver('n')
            ->build();

        $client->run('CREATE CONSTRAINT corp_id_uniq IF NOT EXISTS FOR (c:Corporation) REQUIRE c.corporation_id IS UNIQUE');

        // Corporations — pull name + id from esi_entity_names.
        // JDBC URL inlined into the iterate inner query because APOC's
        // periodic.iterate doesn't forward params reliably into
        // apoc.load.jdbc's first argument.
        $cypher = sprintf(<<<'CYPHER'
            CALL apoc.periodic.iterate(
              "CALL apoc.load.jdbc('%s', 'SELECT entity_id AS id, name FROM esi_entity_names WHERE category = \"corporation\"') YIELD row RETURN row",
              'MERGE (c:Corporation {corporation_id: toInteger(row.id)}) SET c.name = row.name',
              {batchSize: 5000, parallel: false}
            ) YIELD batches, total, committedOperations RETURN batches, total, committedOperations
            CYPHER, $jdbcUrl);
        $r = $client->run($cypher);
        foreach ($r as $row) {
            $this->info(sprintf('Corporations: batches=%d total=%d committed=%d',
                $row->get('batches'), $row->get('total'), $row->get('committedOperations')));
        }

        // IN_ALLIANCE — current corp→alliance edge (end_date IS NULL).
        $cypher = sprintf(<<<'CYPHER'
            CALL apoc.periodic.iterate(
              "CALL apoc.load.jdbc('%s', 'SELECT corporation_id, alliance_id FROM corporation_alliance_history WHERE end_date IS NULL AND alliance_id IS NOT NULL') YIELD row RETURN row",
              'MATCH (c:Corporation {corporation_id: toInteger(row.corporation_id)})
               MATCH (a:Alliance {alliance_id: toInteger(row.alliance_id)})
               MERGE (c)-[:IN_ALLIANCE]->(a)',
              {batchSize: 2000, parallel: false}
            ) YIELD batches, total, committedOperations RETURN batches, total, committedOperations
            CYPHER, $jdbcUrl);
        $r = $client->run($cypher);
        foreach ($r as $row) {
            $this->info(sprintf('IN_ALLIANCE: batches=%d total=%d committed=%d',
                $row->get('batches'), $row->get('total'), $row->get('committedOperations')));
        }

        // MEMBER_OF_CORP — current character→corp edge.
        $cypher = sprintf(<<<'CYPHER'
            CALL apoc.periodic.iterate(
              "CALL apoc.load.jdbc('%s', 'SELECT character_id, corporation_id FROM character_corporation_history WHERE end_date IS NULL AND is_deleted = 0') YIELD row RETURN row",
              'MATCH (c:Corporation {corporation_id: toInteger(row.corporation_id)})
               MATCH (p:CICharacter {character_id: toInteger(row.character_id)})
               MERGE (p)-[:MEMBER_OF_CORP]->(c)',
              {batchSize: 5000, parallel: false}
            ) YIELD batches, total, committedOperations RETURN batches, total, committedOperations
            CYPHER, $jdbcUrl);
        $r = $client->run($cypher);
        foreach ($r as $row) {
            $this->info(sprintf('MEMBER_OF_CORP: batches=%d total=%d committed=%d',
                $row->get('batches'), $row->get('total'), $row->get('committedOperations')));
        }

        return self::SUCCESS;
    }
}

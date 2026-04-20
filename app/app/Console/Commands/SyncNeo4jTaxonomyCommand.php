<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;

/**
 * Phase B2 + B3: hull taxonomy + doctrine layer.
 *
 * :Hull  — every ship type referenced in ship_class_category_mapping
 *          + role category as a property.
 * :HullClass — role categories (fc, command, logi, tackle, bomber,
 *          mainline_dps, other).
 * :Doctrine — active auto_doctrines rows with hull + role_key + name
 *          + confidence.
 * Edges:
 *   (:Hull)-[:IN_CLASS]->(:HullClass)
 *   (:Doctrine)-[:USES_HULL]->(:Hull)
 *   (:CICharacter)-[:FLIES_DOCTRINE {n}]->(:Doctrine)
 *
 * Unlocks doctrine-based spy queries:
 *   "pilots in my bloc flying doctrines adopted primarily by
 *    hostile alliances"
 */
class SyncNeo4jTaxonomyCommand extends Command
{
    protected $signature = 'neo4j:sync-taxonomy';

    protected $description = 'Project hull + doctrine taxonomy into Neo4j.';

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

        $client->run('CREATE CONSTRAINT hull_id_uniq IF NOT EXISTS FOR (h:Hull) REQUIRE h.type_id IS UNIQUE');
        $client->run('CREATE CONSTRAINT hullclass_uniq IF NOT EXISTS FOR (c:HullClass) REQUIRE c.category IS UNIQUE');
        $client->run('CREATE CONSTRAINT doctrine_id_uniq IF NOT EXISTS FOR (d:Doctrine) REQUIRE d.id IS UNIQUE');

        // :HullClass — one per distinct category.
        $client->run(<<<'CYPHER'
            UNWIND ['fc','command','logi','tackle','bomber','mainline_dps','dps','other'] AS cat
            MERGE (c:HullClass {category: cat})
        CYPHER);
        $this->info('HullClasses seeded');

        // :Hull + IN_CLASS edges via ship_class_category_mapping.
        $cypher = sprintf(<<<'CYPHER'
            CALL apoc.periodic.iterate(
              "CALL apoc.load.jdbc('%s',
                 'SELECT m.ship_type_id, m.category, t.name FROM ship_class_category_mapping m LEFT JOIN ref_item_types t ON t.id = m.ship_type_id'
              ) YIELD row RETURN row",
              'MERGE (h:Hull {type_id: toInteger(row.ship_type_id)})
               SET h.category = row.category,
                   h.name = row.name
               WITH h, row
               MATCH (c:HullClass {category: row.category})
               MERGE (h)-[:IN_CLASS]->(c)',
              {batchSize: 1000, parallel: false}
            ) YIELD batches, total, committedOperations
            RETURN batches, total, committedOperations
            CYPHER, $jdbc);
        foreach ($client->run($cypher) as $row) {
            $this->info(sprintf('Hulls + IN_CLASS: batches=%d total=%d committed=%d',
                $row->get('batches'), $row->get('total'), $row->get('committedOperations')));
        }

        // :Doctrine (active only) + USES_HULL edges.
        $cypher = sprintf(<<<'CYPHER'
            CALL apoc.periodic.iterate(
              "CALL apoc.load.jdbc('%s',
                 'SELECT id, hull_type_id, role_key, canonical_name, confidence, observation_count FROM auto_doctrines WHERE is_active = 1'
              ) YIELD row RETURN row",
              'MERGE (d:Doctrine {id: toInteger(row.id)})
               SET d.hull_type_id = toInteger(row.hull_type_id),
                   d.role_key = row.role_key,
                   d.name = row.canonical_name,
                   d.confidence = toFloat(row.confidence),
                   d.observations = toInteger(row.observation_count)
               WITH d, row
               MATCH (h:Hull {type_id: toInteger(row.hull_type_id)})
               MERGE (d)-[:USES_HULL]->(h)',
              {batchSize: 2000, parallel: false}
            ) YIELD batches, total, committedOperations
            RETURN batches, total, committedOperations
            CYPHER, $jdbc);
        foreach ($client->run($cypher) as $row) {
            $this->info(sprintf('Doctrines + USES_HULL: batches=%d total=%d committed=%d',
                $row->get('batches'), $row->get('total'), $row->get('committedOperations')));
        }

        // FLIES_DOCTRINE edges — aggregate per (character, doctrine).
        $cypher = sprintf(<<<'CYPHER'
            CALL apoc.periodic.iterate(
              "CALL apoc.load.jdbc('%s',
                 'SELECT p.character_id, p.doctrine_id, COUNT(*) AS n FROM auto_doctrine_pilots p JOIN auto_doctrines d ON d.id = p.doctrine_id WHERE d.is_active = 1 GROUP BY p.character_id, p.doctrine_id'
              ) YIELD row RETURN row",
              'MATCH (c:CICharacter {character_id: toInteger(row.character_id)})
               MATCH (d:Doctrine {id: toInteger(row.doctrine_id)})
               MERGE (c)-[r:FLIES_DOCTRINE]->(d)
               SET r.n_battles = toInteger(row.n)',
              {batchSize: 5000, parallel: false}
            ) YIELD batches, total, committedOperations
            RETURN batches, total, committedOperations
            CYPHER, $jdbc);
        foreach ($client->run($cypher) as $row) {
            $this->info(sprintf('FLIES_DOCTRINE: batches=%d total=%d committed=%d',
                $row->get('batches'), $row->get('total'), $row->get('committedOperations')));
        }

        $client->run('CREATE INDEX doctrine_role IF NOT EXISTS FOR (d:Doctrine) ON (d.role_key)');

        return self::SUCCESS;
    }
}

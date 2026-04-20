<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;

/**
 * Project coalition_blocs + coalition_entity_labels into Neo4j as
 * (:Bloc) nodes and (:Alliance)-[:IN_BLOC]->(:Bloc) edges.
 *
 * Phase 0.3 of the find-spy plan. Small footprint (6-7 blocs,
 * ~45 alliance labels), but unlocks cross-subsystem queries like
 * "from my bloc to every hostile alliance's bloc".
 *
 * Idempotent — MERGE on both sides. Safe to re-run every time
 * coalition_entity_labels changes.
 */
class SyncNeo4jBlocsCommand extends Command
{
    protected $signature = 'neo4j:sync-blocs';

    protected $description = 'Project coalition_blocs + coalition_entity_labels into Neo4j.';

    public function handle(): int
    {
        $uri = (string) env('NEO4J_BOLT_URI', 'bolt://neo4j:7687');
        $user = (string) env('NEO4J_USER', 'neo4j');
        $pw = (string) env('NEO4J_PASSWORD', '');

        $client = ClientBuilder::create()
            ->withDriver('n', $uri, Authenticate::basic($user, $pw))
            ->withDefaultDriver('n')
            ->build();

        $client->run('CREATE CONSTRAINT bloc_id_uniq IF NOT EXISTS FOR (b:Bloc) REQUIRE b.id IS UNIQUE');

        $blocRows = DB::table('coalition_blocs')->get();
        foreach ($blocRows as $b) {
            $client->run(
                'MERGE (bl:Bloc {id: $id}) SET bl.name = $name',
                ['id' => (int) $b->id, 'name' => (string) $b->display_name],
            );
        }
        $this->info(sprintf('Blocs upserted: %d', $blocRows->count()));

        $allianceEdges = 0;
        foreach (DB::table('coalition_entity_labels')
            ->where('entity_type', 'alliance')
            ->where('is_active', 1)
            ->get() as $el) {
            $client->run(
                'MATCH (a:Alliance {alliance_id: $aid}) MATCH (bl:Bloc {id: $bid}) MERGE (a)-[:IN_BLOC]->(bl)',
                ['aid' => (int) $el->entity_id, 'bid' => (int) $el->bloc_id],
            );
            $allianceEdges++;
        }
        $this->info(sprintf('Alliance IN_BLOC edges attempted: %d', $allianceEdges));

        // Corp-level bloc labels go to :Corporation when that node type
        // lands in Phase 0.2. For now they're held in MariaDB only.
        return self::SUCCESS;
    }
}

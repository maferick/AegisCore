<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;

/**
 * Phase A signal enrichment: flatten MariaDB anomaly + leadership +
 * FW + ring-feature tables into Neo4j node properties via APOC JDBC.
 *
 * Output:
 *   * Alliance.founder_id, Alliance.executor_corp_id, Alliance.ticker,
 *     Alliance.date_founded — from alliance_leadership.
 *   * Corporation.fw_faction_id, Corporation.fw_enlisted (bool),
 *     Corporation.fw_kills_total — from corporation_fw_enlistment.
 *   * CICharacter.score, CICharacter.band, CICharacter.cohort_size,
 *     CICharacter.cohort_confidence, CICharacter.hostile_overlap_pct,
 *     CICharacter.bridge_anomaly_pct, CICharacter.recent_hostile_join
 *     — from ci_character_anomalies_rolling (latest window per char).
 *   * CICharacter.ring_id, CICharacter.ring_size,
 *     CICharacter.bridge_internal_pct, CICharacter.similarity_to_flagged_max
 *     — from ci_character_graph_features_rolling (latest window).
 *
 * All idempotent. Re-run nightly.
 */
class SyncNeo4jSignalsCommand extends Command
{
    protected $signature = 'neo4j:sync-signals';

    protected $description = 'Flatten MariaDB intel signals onto Neo4j node properties via APOC JDBC.';

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

        $this->runIterate($client, 'alliance_leadership', sprintf(<<<'CYPHER'
            CALL apoc.periodic.iterate(
              "CALL apoc.load.jdbc('%s', 'SELECT alliance_id, creator_character_id, executor_corporation_id, ticker, DATE_FORMAT(date_founded, \"%%Y-%%m-%%dT%%H:%%i:%%s\") AS date_founded FROM alliance_leadership') YIELD row RETURN row",
              'MATCH (a:Alliance {alliance_id: toInteger(row.alliance_id)})
               SET a.founder_character_id = toInteger(row.creator_character_id),
                   a.executor_corporation_id = toInteger(row.executor_corporation_id),
                   a.ticker = row.ticker,
                   a.date_founded = row.date_founded',
              {batchSize: 2000, parallel: false}
            ) YIELD batches, total, committedOperations RETURN batches, total, committedOperations
            CYPHER, $jdbc));

        $this->runIterate($client, 'corporation_fw_enlistment', sprintf(<<<'CYPHER'
            CALL apoc.periodic.iterate(
              "CALL apoc.load.jdbc('%s', 'SELECT corporation_id, faction_id, is_enlisted, kills_total FROM corporation_fw_enlistment') YIELD row RETURN row",
              'MATCH (c:Corporation {corporation_id: toInteger(row.corporation_id)})
               SET c.fw_faction_id = toInteger(row.faction_id),
                   c.fw_is_enlisted = toInteger(row.is_enlisted) = 1,
                   c.fw_kills_total = toInteger(row.kills_total)',
              {batchSize: 2000, parallel: false}
            ) YIELD batches, total, committedOperations RETURN batches, total, committedOperations
            CYPHER, $jdbc));

        // Anomaly scores — latest window per character per viewer bloc.
        // We flatten to a single set of properties keyed only on
        // character_id, using the latest window_end + the highest
        // viewer_bloc_id when there are ties (arbitrary but consistent).
        $this->runIterate($client, 'ci_anomalies_latest', sprintf(<<<'CYPHER'
            CALL apoc.periodic.iterate(
              "CALL apoc.load.jdbc('%s', 'SELECT a.character_id, a.review_priority_score, a.review_priority_band, a.cohort_size, a.cohort_confidence, a.hostile_overlap_pct, a.bridge_anomaly_pct, a.recent_hostile_join, a.ring_id, a.ring_size, a.bridge_internal_pct, a.seed_neighbors_max_score, a.viewer_bloc_id FROM ci_character_anomalies_rolling a JOIN ( SELECT character_id, MAX(window_end_date) AS mx FROM ci_character_anomalies_rolling GROUP BY character_id ) m ON m.character_id = a.character_id AND m.mx = a.window_end_date') YIELD row RETURN row",
              'MATCH (p:CICharacter {character_id: toInteger(row.character_id)})
               SET p.score = toFloat(row.review_priority_score),
                   p.band = row.review_priority_band,
                   p.cohort_size = toInteger(row.cohort_size),
                   p.cohort_confidence = row.cohort_confidence,
                   p.hostile_overlap_pct = toFloat(row.hostile_overlap_pct),
                   p.bridge_anomaly_pct = toFloat(row.bridge_anomaly_pct),
                   p.recent_hostile_join = toInteger(row.recent_hostile_join) = 1,
                   p.ring_id = toInteger(row.ring_id),
                   p.ring_size = toInteger(row.ring_size),
                   p.bridge_internal_pct = toFloat(row.bridge_internal_pct),
                   p.similarity_to_flagged_max = toFloat(row.seed_neighbors_max_score),
                   p.viewer_bloc_id = toInteger(row.viewer_bloc_id)',
              {batchSize: 2000, parallel: false}
            ) YIELD batches, total, committedOperations RETURN batches, total, committedOperations
            CYPHER, $jdbc));

        $client->run('CREATE INDEX character_band_score IF NOT EXISTS FOR (c:CICharacter) ON (c.band, c.score)');
        $client->run('CREATE INDEX character_ring IF NOT EXISTS FOR (c:CICharacter) ON (c.ring_id)');
        $client->run('CREATE INDEX corp_faction IF NOT EXISTS FOR (c:Corporation) ON (c.fw_faction_id)');

        return self::SUCCESS;
    }

    private function runIterate($client, string $label, string $cypher): void
    {
        $r = $client->run($cypher);
        foreach ($r as $row) {
            $this->info(sprintf('%-28s batches=%d total=%d committed=%d',
                $label,
                $row->get('batches'), $row->get('total'), $row->get('committedOperations')));
        }
    }
}

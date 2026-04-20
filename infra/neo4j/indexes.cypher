// Neo4j index + constraint baseline for AegisCore's spy-find workload.
// Run once per deploy via:
//   bash scripts/neo4j-apply-indexes.sh
// or manually:
//   docker compose exec -T neo4j cypher-shell -u neo4j -p $NEO4J_PASSWORD \
//     -d neo4j < infra/neo4j/indexes.cypher

// -----------------------------------------------------------------
// Uniqueness constraints — one per node type keyed on id.
// -----------------------------------------------------------------
CREATE CONSTRAINT ci_alliance_id_uniq IF NOT EXISTS
  FOR (a:CIAlliance) REQUIRE a.alliance_id IS UNIQUE;

CREATE CONSTRAINT corp_id_uniq IF NOT EXISTS
  FOR (c:Corporation) REQUIRE c.corporation_id IS UNIQUE;

CREATE CONSTRAINT bloc_id_uniq IF NOT EXISTS
  FOR (b:Bloc) REQUIRE b.id IS UNIQUE;

CREATE CONSTRAINT theater_id_uniq IF NOT EXISTS
  FOR (t:Theater) REQUIRE t.id IS UNIQUE;

CREATE CONSTRAINT hull_id_uniq IF NOT EXISTS
  FOR (h:Hull) REQUIRE h.type_id IS UNIQUE;

CREATE CONSTRAINT hullclass_uniq IF NOT EXISTS
  FOR (c:HullClass) REQUIRE c.category IS UNIQUE;

CREATE CONSTRAINT doctrine_id_uniq IF NOT EXISTS
  FOR (d:Doctrine) REQUIRE d.id IS UNIQUE;

// -----------------------------------------------------------------
// Secondary indexes — one per hot-path predicate.
// -----------------------------------------------------------------

// CICharacter — used in every dashboard + dossier read path.
CREATE INDEX ci_char_character_id IF NOT EXISTS
  FOR (c:CICharacter) ON (c.character_id);
CREATE INDEX ci_char_band_score IF NOT EXISTS
  FOR (c:CICharacter) ON (c.band, c.score);
CREATE INDEX ci_char_ring IF NOT EXISTS
  FOR (c:CICharacter) ON (c.ring_id);
CREATE INDEX ci_char_bloc IF NOT EXISTS
  FOR (c:CICharacter) ON (c.viewer_bloc_id);

// Corporation — FW filter hits this every time.
CREATE INDEX corp_faction IF NOT EXISTS
  FOR (c:Corporation) ON (c.fw_faction_id);
CREATE INDEX corp_fw_enlisted IF NOT EXISTS
  FOR (c:Corporation) ON (c.fw_is_enlisted);

// Alliance — sometimes we filter by bloc directly on alliance props
// (instead of traversing IN_BLOC).
CREATE INDEX alliance_bloc IF NOT EXISTS
  FOR (a:Alliance) ON (a.bloc_id);
CREATE INDEX alliance_founder IF NOT EXISTS
  FOR (a:Alliance) ON (a.founder_character_id);

// Theater — most queries filter by recency + system.
CREATE INDEX theater_end IF NOT EXISTS
  FOR (t:Theater) ON (t.end_time);
CREATE INDEX theater_system IF NOT EXISTS
  FOR (t:Theater) ON (t.primary_system_id);

// Doctrine — role filter hits this.
CREATE INDEX doctrine_role IF NOT EXISTS
  FOR (d:Doctrine) ON (d.role_key);
CREATE INDEX doctrine_hull IF NOT EXISTS
  FOR (d:Doctrine) ON (d.hull_type_id);

// System sov + spatial.
CREATE INDEX system_sov_alliance IF NOT EXISTS
  FOR (s:System) ON (s.sov_alliance_id);

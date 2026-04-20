// FastRP embeddings + Filtered KNN similarity.
// Run via: docker compose exec -T neo4j cypher-shell -u neo4j -p $NEO4J_PASSWORD -d neo4j -f /scripts/compute-embeddings.cypher

CALL gds.graph.drop('ci_embed', false) YIELD graphName RETURN graphName;

CALL gds.graph.project(
  'ci_embed',
  'CICharacter',
  {
    CI_CO_OCCURS_WITH: {
      orientation: 'UNDIRECTED',
      properties: { event_count: { property: 'event_count', defaultValue: 1.0 } }
    }
  }
) YIELD nodeCount, relationshipCount RETURN nodeCount, relationshipCount;

CALL gds.fastRP.write(
  'ci_embed',
  {
    embeddingDimension: 128,
    iterationWeights: [0.0, 1.0, 1.0, 0.8],
    relationshipWeightProperty: 'event_count',
    writeProperty: 'embedding',
    randomSeed: 42
  }
) YIELD nodeCount, computeMillis, writeMillis RETURN nodeCount, computeMillis, writeMillis;

CALL gds.graph.drop('ci_embed', false) YIELD graphName RETURN graphName;

CALL gds.graph.project(
  'ci_embed',
  { CICharacter: { properties: ['embedding'] } },
  { CI_CO_OCCURS_WITH: { orientation: 'UNDIRECTED' } }
) YIELD nodeCount, relationshipCount RETURN nodeCount, relationshipCount;

CALL gds.knn.write(
  'ci_embed',
  {
    nodeProperties: 'embedding',
    topK: 20,
    similarityCutoff: 0.5,
    writeRelationshipType: 'SIMILAR_TO_V2',
    writeProperty: 'score'
  }
) YIELD nodesCompared, relationshipsWritten, computeMillis RETURN nodesCompared, relationshipsWritten, computeMillis;

CALL gds.graph.drop('ci_embed', false) YIELD graphName RETURN graphName;

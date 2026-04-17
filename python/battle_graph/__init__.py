"""Battle-scoped Neo4j graph projection + metrics write-back.

Implements Spec 2. For a single (battle_id, alliance_id) pair the module
projects a pilot graph with edges derived from bucketed co-presence,
victim overlap, and phase co-occurrence, runs Neo4j GDS algorithms
(weighted degree, PageRank, Louvain; optional betweenness / clustering
coefficient) and writes raw metrics to ``battle_character_graph_metrics``.

The module follows the ``theater_clustering`` layout so operator output
and env handling are consistent across Python workers.
"""

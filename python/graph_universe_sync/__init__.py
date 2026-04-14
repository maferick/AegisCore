"""Project the SDE universe topology from MariaDB into Neo4j.

Runs as a one-shot container (`make neo4j-sync-universe`) — see
`runner.py` for the orchestration sequence and ADR-0001 for the
data-ownership rationale (MariaDB canonical, Neo4j as derived projection).
"""

__version__ = "0.1.0"

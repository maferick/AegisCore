"""Stage functions: read ref_* from MariaDB, MERGE into Neo4j.

Each `project_*` function takes a `pymysql` connection + a `Neo4jClient`
+ batch-size, returns counts (`{nodes: int, edges: int}`) for outbox
metrics. Stages are intentionally independent: callers may run any
subset (the `--only` flag) and the projection still converges to a
consistent state under MERGE semantics.

Batching uses Neo4j's `UNWIND $rows AS r MERGE ...` pattern. Chunk size
is tuned for ~1MB Bolt payloads; small enough to keep the server's
transaction log happy, big enough that round-trips don't dominate.
"""

from __future__ import annotations

from typing import Any, Iterable, Iterator

import pymysql

from graph_universe_sync.db import fetch_all
from graph_universe_sync.log import get
from graph_universe_sync.neo4j_client import Neo4jClient


log = get(__name__)


# New Eden cluster ID range. Keeps wormhole + abyssal noise out of the
# stargate graph. Constellation and region IDs follow the same band
# offset (10000000..10999999 for regions, 20000000..20999999 for
# constellations) — we filter by the dependent solar-system band so
# region/constellation rows still flow in even if some of them are
# wormhole-only.
NEW_EDEN_SYSTEM_LO: int = 30_000_000
NEW_EDEN_SYSTEM_HI: int = 30_999_999
NEW_EDEN_REGION_LO: int = 10_000_000
NEW_EDEN_REGION_HI: int = 10_999_999
NEW_EDEN_CONSTELLATION_LO: int = 20_000_000
NEW_EDEN_CONSTELLATION_HI: int = 20_999_999


def _chunked(rows: list[dict], size: int) -> Iterator[list[dict]]:
    """Yield rows in batches of `size`. Last batch may be smaller."""
    for i in range(0, len(rows), size):
        yield rows[i:i + size]


def _id_filter(new_eden_only: bool, column: str, lo: int, hi: int) -> str:
    """Inline WHERE fragment; returns an empty string when filter is off."""
    if not new_eden_only:
        return ""
    return f" WHERE {column} BETWEEN {lo} AND {hi}"


# ---------------------------------------------------------------------------
# Regions
# ---------------------------------------------------------------------------

def project_regions(
    conn: pymysql.connections.Connection,
    nj: Neo4jClient,
    *,
    batch_size: int,
    new_eden_only: bool,
    dry_run: bool,
) -> dict[str, int]:
    """MERGE (:Region) for every region row."""
    rows = fetch_all(
        conn,
        "SELECT id, name, position_x, position_y, position_z FROM ref_regions"
        + _id_filter(new_eden_only, "id", NEW_EDEN_REGION_LO, NEW_EDEN_REGION_HI),
    )
    log.info("regions fetched", count=len(rows))
    if dry_run:
        return {"nodes": len(rows), "edges": 0}

    cypher = (
        "UNWIND $rows AS r "
        "MERGE (n:Region {id: r.id}) "
        "SET n.name = r.name, "
        "    n.position_x = r.position_x, "
        "    n.position_y = r.position_y, "
        "    n.position_z = r.position_z"
    )
    _run_batches(nj, cypher, rows, batch_size, label="regions")
    return {"nodes": len(rows), "edges": 0}


# ---------------------------------------------------------------------------
# Constellations
# ---------------------------------------------------------------------------

def project_constellations(
    conn: pymysql.connections.Connection,
    nj: Neo4jClient,
    *,
    batch_size: int,
    new_eden_only: bool,
    dry_run: bool,
) -> dict[str, int]:
    """MERGE (:Constellation) and (:Constellation)-[:IN_REGION]->(:Region)."""
    rows = fetch_all(
        conn,
        "SELECT id, name, region_id, position_x, position_y, position_z "
        "FROM ref_constellations"
        + _id_filter(new_eden_only, "id", NEW_EDEN_CONSTELLATION_LO, NEW_EDEN_CONSTELLATION_HI),
    )
    log.info("constellations fetched", count=len(rows))
    if dry_run:
        return {"nodes": len(rows), "edges": len(rows)}

    cypher_nodes = (
        "UNWIND $rows AS r "
        "MERGE (c:Constellation {id: r.id}) "
        "SET c.name = r.name, "
        "    c.region_id = r.region_id, "
        "    c.position_x = r.position_x, "
        "    c.position_y = r.position_y, "
        "    c.position_z = r.position_z"
    )
    _run_batches(nj, cypher_nodes, rows, batch_size, label="constellations")

    # Containment: (Constellation)-[:IN_REGION]->(Region). Region nodes
    # must already exist (project_regions ran first); MATCH avoids
    # accidentally creating empty :Region nodes for non-New-Eden
    # constellations when filtering is on.
    cypher_edges = (
        "UNWIND $rows AS r "
        "MATCH (c:Constellation {id: r.id}) "
        "MATCH (reg:Region {id: r.region_id}) "
        "MERGE (c)-[:IN_REGION]->(reg)"
    )
    _run_batches(nj, cypher_edges, rows, batch_size, label="constellation_in_region")
    return {"nodes": len(rows), "edges": len(rows)}


# ---------------------------------------------------------------------------
# Systems
# ---------------------------------------------------------------------------

def project_systems(
    conn: pymysql.connections.Connection,
    nj: Neo4jClient,
    *,
    batch_size: int,
    new_eden_only: bool,
    dry_run: bool,
) -> dict[str, int]:
    """MERGE (:System) and (:System)-[:IN_CONSTELLATION]->(:Constellation)."""
    rows = fetch_all(
        conn,
        "SELECT id, name, region_id, constellation_id, "
        "       security_status, security_class, "
        "       hub, border, international, regional, "
        "       position_x, position_y, position_z, "
        "       position2d_x, position2d_y "
        "FROM ref_solar_systems"
        + _id_filter(new_eden_only, "id", NEW_EDEN_SYSTEM_LO, NEW_EDEN_SYSTEM_HI),
    )
    # Coerce booleans — pymysql returns 0/1 for TINYINT(1).
    for r in rows:
        for col in ("hub", "border", "international", "regional"):
            r[col] = bool(r[col])
    log.info("systems fetched", count=len(rows))
    if dry_run:
        return {"nodes": len(rows), "edges": len(rows)}

    cypher_nodes = (
        "UNWIND $rows AS r "
        "MERGE (s:System {id: r.id}) "
        "SET s.name = r.name, "
        "    s.region_id = r.region_id, "
        "    s.constellation_id = r.constellation_id, "
        "    s.security_status = r.security_status, "
        "    s.security_class = r.security_class, "
        "    s.hub = r.hub, "
        "    s.border = r.border, "
        "    s.international = r.international, "
        "    s.regional = r.regional, "
        "    s.position_x = r.position_x, "
        "    s.position_y = r.position_y, "
        "    s.position_z = r.position_z, "
        "    s.position2d_x = r.position2d_x, "
        "    s.position2d_y = r.position2d_y"
    )
    _run_batches(nj, cypher_nodes, rows, batch_size, label="systems")

    cypher_edges = (
        "UNWIND $rows AS r "
        "MATCH (s:System {id: r.id}) "
        "MATCH (c:Constellation {id: r.constellation_id}) "
        "MERGE (s)-[:IN_CONSTELLATION]->(c)"
    )
    _run_batches(nj, cypher_edges, rows, batch_size, label="system_in_constellation")
    return {"nodes": len(rows), "edges": len(rows)}


# ---------------------------------------------------------------------------
# Stargate edges
# ---------------------------------------------------------------------------

def project_stargate_edges(
    conn: pymysql.connections.Connection,
    nj: Neo4jClient,
    *,
    batch_size: int,
    new_eden_only: bool,
    dry_run: bool,
) -> dict[str, int]:
    """MERGE (:System)-[:JUMPS_TO]-(:System), one undirected edge per gate pair.

    Dedupe in SQL with LEAST/GREATEST so each (a,b) appears once. Then a
    single MERGE in Cypher; the underlying relationship is undirected
    (Cypher's MERGE on `()-[:R]-()` reuses any direction).
    """
    where_clause = ""
    if new_eden_only:
        where_clause = (
            f" AND solar_system_id BETWEEN {NEW_EDEN_SYSTEM_LO} AND {NEW_EDEN_SYSTEM_HI} "
            f"AND destination_system_id BETWEEN {NEW_EDEN_SYSTEM_LO} AND {NEW_EDEN_SYSTEM_HI}"
        )
    rows = fetch_all(
        conn,
        "SELECT LEAST(solar_system_id, destination_system_id)    AS a, "
        "       GREATEST(solar_system_id, destination_system_id) AS b "
        "FROM ref_stargates "
        "WHERE destination_system_id IS NOT NULL"
        + where_clause + " "
        "GROUP BY a, b",
    )
    log.info("stargate edges fetched", count=len(rows))
    if dry_run:
        return {"nodes": 0, "edges": len(rows)}

    cypher = (
        "UNWIND $rows AS j "
        "MATCH (x:System {id: j.a}) "
        "MATCH (y:System {id: j.b}) "
        "MERGE (x)-[:JUMPS_TO]-(y)"
    )
    _run_batches(nj, cypher, rows, batch_size, label="jumps_to")
    return {"nodes": 0, "edges": len(rows)}


# ---------------------------------------------------------------------------
# NPC Stations
# ---------------------------------------------------------------------------

def project_stations(
    conn: pymysql.connections.Connection,
    nj: Neo4jClient,
    *,
    batch_size: int,
    new_eden_only: bool,
    dry_run: bool,
) -> dict[str, int]:
    """MERGE (:Station) and (:System)-[:HAS_STATION]->(:Station).

    Source: `ref_npc_stations`. Phase 1 only ships NPC stations; player
    structures will arrive via a separate ESI projection later.

    The station name isn't in `ref_npc_stations` directly — CCP composes
    it from the operation name + the orbiting body. We pass the
    operation_id forward and let consumers resolve via Cypher join when
    they need a display name; or extend this stage later to JOIN with
    ref_station_operations / ref_npc_corporations on the SQL side.
    """
    where_clause = ""
    if new_eden_only:
        where_clause = (
            f" WHERE solar_system_id BETWEEN {NEW_EDEN_SYSTEM_LO} AND {NEW_EDEN_SYSTEM_HI}"
        )
    rows = fetch_all(
        conn,
        "SELECT id, solar_system_id, owner_id, operation_id, type_id, "
        "       position_x, position_y, position_z "
        "FROM ref_npc_stations"
        + where_clause,
    )
    log.info("npc stations fetched", count=len(rows))
    if dry_run:
        return {"nodes": len(rows), "edges": len(rows)}

    cypher_nodes = (
        "UNWIND $rows AS r "
        "MERGE (st:Station {id: r.id}) "
        "SET st.solar_system_id = r.solar_system_id, "
        "    st.owner_id = r.owner_id, "
        "    st.operation_id = r.operation_id, "
        "    st.type_id = r.type_id, "
        "    st.position_x = r.position_x, "
        "    st.position_y = r.position_y, "
        "    st.position_z = r.position_z"
    )
    _run_batches(nj, cypher_nodes, rows, batch_size, label="stations")

    cypher_edges = (
        "UNWIND $rows AS r "
        "MATCH (s:System {id: r.solar_system_id}) "
        "MATCH (st:Station {id: r.id}) "
        "MERGE (s)-[:HAS_STATION]->(st)"
    )
    _run_batches(nj, cypher_edges, rows, batch_size, label="has_station")
    return {"nodes": len(rows), "edges": len(rows)}


# ---------------------------------------------------------------------------
# internals
# ---------------------------------------------------------------------------

def _run_batches(
    nj: Neo4jClient,
    cypher: str,
    rows: Iterable[dict[str, Any]],
    batch_size: int,
    *,
    label: str,
) -> None:
    """Stream rows through Neo4j in chunks and log batch progress."""
    rows_list = list(rows)
    total = len(rows_list)
    if total == 0:
        log.info("batch skipped (no rows)", stage=label)
        return
    sent = 0
    with nj.session() as s:
        for chunk in _chunked(rows_list, batch_size):
            s.run(cypher, rows=chunk).consume()
            sent += len(chunk)
            log.debug("batch sent", stage=label, sent=sent, total=total)
    log.info("stage complete", stage=label, rows=total)

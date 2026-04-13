"""Generic JSONL → ref_* table bulk loader.

Walks the `SPECS` list from `schema.py`, streams each file one row at a
time, extracts typed scalar columns + a JSON overflow column, and
bulk-INSERTs using pymysql `executemany` in `cfg.batch_size` chunks.

Why streaming + batched inserts (and not LOAD DATA LOCAL INFILE):
  * LOAD DATA needs per-file CSV conversion and local_infile=1 on both
    client and server. Batched INSERTs work out of the box against a
    stock MariaDB.
  * The biggest file (mapMoons.jsonl at ~344k rows) still finishes in
    well under a minute on a $5 VPS with batch_size=2000. Total load for
    the whole SDE (~664k rows) completes comfortably inside the single
    transaction the ADR-0001 contract wants.

Missing or malformed fields degrade to NULL rather than aborting the
import. An entire malformed row (non-JSON line) is logged and skipped —
the alternative (abort the transaction over one bad byte from CCP)
isn't worth the operational pain.
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterator

import pymysql

from sde_importer.log import get
from sde_importer.schema import Column, SPECS, SKIPPED_FILES, TableSpec

log = get(__name__)


# Truncation limits for string columns. If a value exceeds these sizes we
# log a warning and truncate rather than letting MariaDB raise "Data too
# long". Keep in sync with the Laravel migrations.
_STR_MAX = 255
_NAME_MAX = 100


@dataclass
class LoadCounts:
    """Per-spec load result, aggregated into the snapshot outbox event."""
    table: str
    file: str
    rows_read: int
    rows_inserted: int
    rows_skipped: int


def load_all(conn: pymysql.connections.Connection, extract_dir: Path, batch_size: int) -> list[LoadCounts]:
    """Truncate all ref_* tables and reload from JSONL files.

    Runs inside the caller's transaction. The caller is responsible for
    BEGIN / COMMIT / ROLLBACK semantics — we only emit SQL.
    """
    # Sanity-check: warn about JSONL files on disk that we don't have a
    # spec for. These are new additions from CCP; we'll skip them this
    # run but an operator should see the warning and open a PR to add
    # them to schema.py.
    _warn_on_unmapped_files(extract_dir)

    counts: list[LoadCounts] = []
    with conn.cursor() as cur:
        # Order doesn't matter structurally (no FKs), but we truncate
        # everything first so a partial spec list can't leave stale rows
        # from a previous run.
        for spec in SPECS:
            cur.execute(f"DELETE FROM {spec.table}")
        log.info("ref_* tables cleared", tables=len(SPECS))

        for spec in SPECS:
            counts.append(_load_one(cur, extract_dir, spec, batch_size))

    return counts


def _load_one(cur, extract_dir: Path, spec: TableSpec, batch_size: int) -> LoadCounts:
    """Stream one JSONL file into its ref_* table."""
    jsonl_path = extract_dir / spec.file
    if not jsonl_path.exists():
        log.warning("spec file missing from extract — skipping", file=spec.file, table=spec.table)
        return LoadCounts(spec.table, spec.file, 0, 0, 0)

    col_names = spec.column_names()
    placeholders = ", ".join(["%s"] * len(col_names))
    insert_sql = (
        f"INSERT INTO {spec.table} ({', '.join(col_names)}) VALUES ({placeholders})"
    )

    rows_read = 0
    rows_inserted = 0
    rows_skipped = 0
    batch: list[tuple] = []

    for row in _iter_jsonl(jsonl_path):
        rows_read += 1
        try:
            values = _row_values(spec, row)
        except Exception as exc:
            # One bad row shouldn't abort the transaction. Log and move on.
            rows_skipped += 1
            log.warning(
                "row extract failed — skipping",
                file=spec.file,
                error=str(exc),
                key=row.get("_key") if isinstance(row, dict) else None,
            )
            continue

        batch.append(values)
        if len(batch) >= batch_size:
            cur.executemany(insert_sql, batch)
            rows_inserted += len(batch)
            batch.clear()

    if batch:
        cur.executemany(insert_sql, batch)
        rows_inserted += len(batch)
        batch.clear()

    log.info(
        "loaded",
        table=spec.table,
        file=spec.file,
        rows_read=rows_read,
        rows_inserted=rows_inserted,
        rows_skipped=rows_skipped,
    )
    return LoadCounts(spec.table, spec.file, rows_read, rows_inserted, rows_skipped)


def _iter_jsonl(path: Path) -> Iterator[dict]:
    """Yield decoded rows from a JSONL file. Bad lines are logged and skipped."""
    with path.open("r", encoding="utf-8") as fh:
        for lineno, line in enumerate(fh, start=1):
            line = line.strip()
            if not line:
                continue
            try:
                yield json.loads(line)
            except json.JSONDecodeError as exc:
                log.warning(
                    "jsonl decode failed — skipping line",
                    file=path.name,
                    line=lineno,
                    error=str(exc),
                )
                continue


def _row_values(spec: TableSpec, row: dict) -> tuple:
    """Produce the tuple of values to pass to executemany, per spec.columns."""
    return tuple(_extract(col, row) for col in spec.columns)


def _extract(col: Column, row: dict) -> Any:
    """Pull `col.source` out of `row` and coerce per `col.kind`."""
    if col.kind == "overflow":
        # Full original row, JSON-serialized for the `data` LONGTEXT column.
        return json.dumps(row, separators=(",", ":"), ensure_ascii=False)

    raw = _dotted(row, col.source)
    if raw is None:
        return None

    kind = col.kind
    if kind == "int" or kind == "bigint":
        try:
            return int(raw)
        except (TypeError, ValueError):
            return None
    if kind == "float":
        try:
            return float(raw)
        except (TypeError, ValueError):
            return None
    if kind == "bool":
        return bool(raw)
    if kind == "str":
        s = str(raw)
        if len(s) > _STR_MAX:
            log.warning("string truncated", column=col.name, length=len(s))
            s = s[:_STR_MAX]
        return s
    if kind == "name":
        # i18n dict → .en; else fall back to str(). Truncated to _NAME_MAX
        # to fit the `name` VARCHAR(100) columns in the migrations.
        if isinstance(raw, dict):
            s = raw.get("en") or next(iter(raw.values()), "")
        else:
            s = str(raw)
        if len(s) > _NAME_MAX:
            log.warning("name truncated", column=col.name, length=len(s))
            s = s[:_NAME_MAX]
        return s
    if kind == "json":
        return json.dumps(raw, separators=(",", ":"), ensure_ascii=False)

    raise ValueError(f"unknown column kind: {kind}")


def _dotted(row: dict, path: str) -> Any:
    """Walk `row` along a dotted path. Missing intermediates yield None.

    Special-case `_key` — it's always top-level and never nested.
    """
    if path == "_key":
        return row.get("_key")
    cur: Any = row
    for part in path.split("."):
        if not isinstance(cur, dict):
            return None
        cur = cur.get(part)
        if cur is None:
            return None
    return cur


def _warn_on_unmapped_files(extract_dir: Path) -> None:
    """Log a warning for any .jsonl file on disk we don't have a spec for."""
    mapped = {spec.file for spec in SPECS} | SKIPPED_FILES
    on_disk = {p.name for p in extract_dir.glob("*.jsonl")}
    unmapped = on_disk - mapped
    if unmapped:
        log.warning(
            "unmapped SDE files present — add to schema.py to import",
            files=",".join(sorted(unmapped)),
        )

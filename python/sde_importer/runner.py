"""End-to-end SDE import orchestrator.

Steps (all inside one MariaDB transaction per ADR-0001 §4):
  1. Download + extract the SDE zip (or reuse an existing extract).
  2. Parse the `_sde.jsonl` manifest for build identity.
  3. BEGIN.
  4. Load every spec in `schema.SPECS` into its ref_* table.
  5. Replace the ref_snapshot row with the new manifest.
  6. Insert the `reference.sde_snapshot_loaded` outbox event.
  7. COMMIT.
  8. Write the new etag (or build number) to `infra/sde/version.txt`
     so the drift-check widget has a pinned reference next time it runs.

Any error before step 7 → ROLLBACK + re-raise. The transaction guarantee
is the whole point of using Python instead of chunked Laravel jobs here.
"""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

from sde_importer.config import Config
from sde_importer.db import connect
from sde_importer.download import DownloadResult, download_and_extract
from sde_importer.loader import LoadCounts, load_all
from sde_importer.log import get
from sde_importer.outbox import emit_snapshot_loaded

log = get(__name__)


def run(cfg: Config) -> int:
    """Execute the full import. Returns process-exit style status (0 = ok)."""
    # Step 1: Acquire the extracted SDE.
    extract_dir, dl = _acquire_sde(cfg)

    if cfg.only_download:
        log.info("only-download mode — skipping DB load", extract_dir=str(extract_dir))
        return 0

    # Step 2: Parse the manifest.
    manifest = _read_manifest(extract_dir)
    build_number = int(manifest["buildNumber"])
    release_date = manifest.get("releaseDate")
    log.info(
        "manifest parsed",
        build_number=build_number,
        release_date=release_date,
    )

    # Steps 3–7: Load everything inside one transaction.
    with connect(cfg) as conn:
        try:
            counts = load_all(conn, extract_dir, cfg.batch_size)
            _write_snapshot_row(conn, build_number, release_date, dl)
            table_counts = {c.table: c.rows_inserted for c in counts}
            emit_snapshot_loaded(
                conn,
                build_number=build_number,
                release_date=release_date,
                etag=(dl.etag if dl else None),
                last_modified=(dl.last_modified if dl else None),
                table_counts=table_counts,
            )
            conn.commit()
            log.info(
                "import committed",
                build_number=build_number,
                tables=len(counts),
                rows_total=sum(table_counts.values()),
            )
        except Exception:
            conn.rollback()
            log.exception("import failed — rolled back")
            raise

    # Step 8: Pin the version for the drift check.
    _write_version_file(cfg.version_file, dl, build_number)
    return 0


def _acquire_sde(cfg: Config) -> tuple[Path, DownloadResult | None]:
    """Either download+extract fresh or reuse an existing extract dir."""
    if cfg.skip_download:
        # Reusing a pre-extracted tree — skip the HTTP fetch entirely.
        # Useful when iterating on loader code against a local copy.
        extract_dir = cfg.extract_dir_override or (cfg.work_dir / "extracted")
        if not extract_dir.exists():
            raise RuntimeError(
                f"--skip-download set but extract dir missing: {extract_dir}"
            )
        log.info("reusing existing extract", extract_dir=str(extract_dir))
        return extract_dir, None

    dl = download_and_extract(cfg.source_url, cfg.work_dir, cfg.download_timeout)
    return dl.extract_dir, dl


def _read_manifest(extract_dir: Path) -> dict[str, Any]:
    """Parse `_sde.jsonl` — a single-row file with the build identity."""
    path = extract_dir / "_sde.jsonl"
    if not path.exists():
        raise RuntimeError(f"missing manifest: {path}")
    with path.open("r", encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if line:
                return json.loads(line)
    raise RuntimeError(f"empty manifest: {path}")


def _write_snapshot_row(
    conn,
    build_number: int,
    release_date: str | None,
    dl: DownloadResult | None,
) -> None:
    """Replace the single ref_snapshot row with the new import's identity."""
    with conn.cursor() as cur:
        cur.execute("DELETE FROM ref_snapshot")
        cur.execute(
            """
            INSERT INTO ref_snapshot
                (build_number, release_date, etag, last_modified)
            VALUES (%s, %s, %s, %s)
            """,
            (
                build_number,
                _parse_iso_for_mysql(release_date),
                (dl.etag if dl else None),
                (dl.last_modified if dl else None),
            ),
        )


def _parse_iso_for_mysql(iso: str | None) -> str | None:
    """Convert CCP's ISO 8601 ('Z' suffix) to a MySQL TIMESTAMP literal.

    MariaDB's TIMESTAMP wants "YYYY-MM-DD HH:MM:SS" without tz. CCP
    already gives UTC so the bare substitution is safe.
    """
    if not iso:
        return None
    # "2026-04-09T11:29:37Z" → "2026-04-09 11:29:37"
    return iso.replace("T", " ").replace("Z", "").split(".")[0]


def _write_version_file(version_file: Path, dl: DownloadResult | None, build_number: int) -> None:
    """Pin the imported version so the daily drift check has something to diff.

    Writes the upstream etag if we have one (that's what the drift check
    compares against); otherwise falls back to the build number as a
    last resort. Either way, one line, newline-terminated.
    """
    pin = (dl.etag if dl and dl.etag else str(build_number))
    version_file.parent.mkdir(parents=True, exist_ok=True)
    version_file.write_text(pin + "\n", encoding="utf-8")
    log.info("version file written", path=str(version_file), pin=pin)

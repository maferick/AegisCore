"""Download + unzip CCP's SDE JSONL tarball.

Streams the download to disk rather than buffering in memory (the zip is
~80MB compressed, ~500MB uncompressed). Captures the HTTP ETag so the
importer can later write it to `infra/sde/version.txt` for the drift
check to compare against.
"""

from __future__ import annotations

import shutil
import zipfile
from dataclasses import dataclass
from pathlib import Path

import httpx

from sde_importer.log import get

log = get(__name__)


@dataclass(frozen=True)
class DownloadResult:
    zip_path: Path
    extract_dir: Path
    etag: str | None
    last_modified: str | None
    bytes_downloaded: int


def download_and_extract(source_url: str, work_dir: Path, timeout: int = 600) -> DownloadResult:
    """Fetch the SDE zip, extract to `work_dir/extracted/`, return metadata."""
    work_dir.mkdir(parents=True, exist_ok=True)

    zip_path = work_dir / "sde.zip"
    extract_dir = work_dir / "extracted"

    # Fresh extract every run. Prior artefacts from a crashed import would
    # otherwise linger and confuse loaders.
    if extract_dir.exists():
        shutil.rmtree(extract_dir)

    log.info("downloading SDE", url=source_url, to=str(zip_path))
    with httpx.stream("GET", source_url, timeout=timeout, follow_redirects=True) as resp:
        resp.raise_for_status()
        etag = resp.headers.get("etag")
        last_modified = resp.headers.get("last-modified")
        bytes_downloaded = 0
        with zip_path.open("wb") as fh:
            for chunk in resp.iter_bytes(chunk_size=1024 * 1024):
                fh.write(chunk)
                bytes_downloaded += len(chunk)

    log.info(
        "download complete",
        bytes=bytes_downloaded,
        etag=etag,
        last_modified=last_modified,
    )

    log.info("extracting", to=str(extract_dir))
    with zipfile.ZipFile(zip_path) as zf:
        zf.extractall(extract_dir)

    # CCP packs files flat at the top level. If they ever nest, flatten so
    # the loaders can find .jsonl files by bare name.
    jsonl_count = sum(1 for _ in extract_dir.rglob("*.jsonl"))
    if not any(extract_dir.glob("*.jsonl")) and jsonl_count > 0:
        inner = next(extract_dir.iterdir())
        for p in inner.iterdir():
            p.rename(extract_dir / p.name)
        inner.rmdir()

    log.info("extract complete", jsonl_files=jsonl_count)

    return DownloadResult(
        zip_path=zip_path,
        extract_dir=extract_dir,
        etag=(etag or "").strip('"') or None,
        last_modified=last_modified,
        bytes_downloaded=bytes_downloaded,
    )

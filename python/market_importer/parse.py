"""Parse EVE Ref's daily market-history CSV files.

File shape (confirmed empirically from 2025-01-01 dump):

    average,date,highest,lowest,order_count,volume,http_last_modified,region_id,type_id
    785,2025-01-01,785,785,7,28404,2025-01-02T11:01:17Z,10000001,20
    3.2,2025-01-01,3.25,2.8,21,26294862,2025-01-02T11:01:17Z,10000001,34
    ...

The column ORDER is not one we want to depend on — EVE Ref could
reshuffle without warning and every row would still be correct JSON.
We parse through `csv.DictReader` which keys by the header row, so
any column reordering is absorbed. An unexpected column missing raises
`CsvFormatError` at the first row we try to read it from, which the
runner logs + treats as a transient failure (the operator will notice
in logs and upstream can fix).

Decompression is in-memory: files are ~600 KB compressed, ~2-4 MB
uncompressed. `bz2.decompress()` on the whole buffer is simpler than
streaming and fits a one-shot importer's resource envelope easily.
"""

from __future__ import annotations

import bz2
import csv
import io
from dataclasses import dataclass
from datetime import date, datetime, timezone
from decimal import Decimal, InvalidOperation
from typing import Iterator


class CsvFormatError(Exception):
    """Raised when a required column is missing or a row's values are
    unparseable. The runner treats this as transient — retry on the
    next run rather than auto-disabling anything."""


@dataclass(frozen=True)
class HistoryRow:
    """One market-history row — shape matches `market_history` columns
    1:1 (plus `source` / `observation_kind` / timestamps which the
    persist layer stamps)."""
    trade_date: date
    region_id: int
    type_id: int
    average: Decimal
    highest: Decimal
    lowest: Decimal
    volume: int
    order_count: int
    http_last_modified: datetime | None


_REQUIRED_COLUMNS = frozenset({
    "date", "region_id", "type_id",
    "average", "highest", "lowest",
    "volume", "order_count",
    # http_last_modified is not in _REQUIRED: the schema allows NULL,
    # and older EVE Ref dumps may predate its introduction. Absent →
    # stored as NULL, rows still import.
})


def parse_day_csv(compressed: bytes) -> Iterator[HistoryRow]:
    """Decompress + parse one day's CSV. Yields `HistoryRow`s.

    The expected-day parameter that might feel natural here is
    deliberately omitted — the runner gets it back via `HistoryRow.trade_date`
    and can spot-check the first row to catch "wrong file for the
    wrong day" bugs cheaply (a bare sanity assert in the runner, not
    a parser concern).
    """
    try:
        decompressed = bz2.decompress(compressed)
    except OSError as exc:
        raise CsvFormatError(f"bz2 decompression failed: {exc}") from exc

    try:
        text = decompressed.decode("utf-8")
    except UnicodeDecodeError as exc:
        raise CsvFormatError(f"UTF-8 decode failed: {exc}") from exc

    reader = csv.DictReader(io.StringIO(text))
    if reader.fieldnames is None:
        raise CsvFormatError("CSV has no header row")

    missing = _REQUIRED_COLUMNS - set(reader.fieldnames)
    if missing:
        raise CsvFormatError(
            f"CSV missing required columns: {sorted(missing)}; "
            f"have {reader.fieldnames}"
        )

    for lineno, raw in enumerate(reader, start=2):  # start=2 → header is line 1
        try:
            yield HistoryRow(
                trade_date=date.fromisoformat(raw["date"]),
                region_id=int(raw["region_id"]),
                type_id=int(raw["type_id"]),
                average=_decimal(raw["average"], lineno, "average"),
                highest=_decimal(raw["highest"], lineno, "highest"),
                lowest=_decimal(raw["lowest"], lineno, "lowest"),
                volume=int(raw["volume"]),
                order_count=int(raw["order_count"]),
                http_last_modified=_iso_ts_opt(raw.get("http_last_modified")),
            )
        except (ValueError, KeyError) as exc:
            raise CsvFormatError(f"line {lineno}: {exc} (row={raw})") from exc


def _decimal(value: str, lineno: int, column: str) -> Decimal:
    """Convert a CSV cell to Decimal. DECIMAL(20,2) accepts up to 2dp;
    EVE Ref publishes more (e.g. `3.2`, `3.25`) — pymysql quantises on
    insert. We pass the string through unmodified so we don't lose any
    upstream precision in this layer; the DB enforces the scale."""
    try:
        return Decimal(value)
    except (InvalidOperation, ValueError) as exc:
        raise ValueError(f"column {column!r} not a decimal: {value!r}") from exc


def _iso_ts_opt(raw: str | None) -> datetime | None:
    """Parse EVE Ref's `http_last_modified` (e.g. `2025-01-02T11:01:17Z`)
    into a UTC-aware datetime. Empty / missing → None."""
    if not raw:
        return None
    s = raw
    if s.endswith("Z"):
        s = s[:-1] + "+00:00"
    try:
        dt = datetime.fromisoformat(s)
    except ValueError:
        return None
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt

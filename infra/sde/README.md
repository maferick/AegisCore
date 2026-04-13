# infra/sde/

Repo-pinned marker for the currently loaded EVE Static Data Export (SDE)
snapshot. The directory is bind-mounted read-only into `php-fpm` and
`scheduler` at `/var/www/sde/` — see `infra/docker-compose.yml`.

## `version.txt`

A single line identifying the pinned SDE version. Format is
intentionally loose — whatever string CCP happens to expose today
(ETag, Last-Modified digest, release tag). The daily
`reference:check-sde-version` check compares it byte-for-byte against
upstream, so the only rule is **it must match whatever CCP serves in
the upstream response**.

**Current state:** `version.txt` is deliberately absent — the Python
`sde_importer` (planned: [ADR-0001](../../docs/adr/0001-static-reference-data.md))
will write this file as part of a successful load. Until then, the
version widget on `/admin` shows "SDE version never checked" / "SDE
not loaded yet".

## Bumping the snapshot

Runbook placeholder — lands alongside the Python importer PR:

1. Download the new SDE tarball from
   `https://developers.eveonline.com/docs/services/static-data/`.
2. Run `make sde-import TARBALL=...` (adds row to `ref_*`, emits
   `reference.sde_snapshot_loaded` outbox event).
3. Importer writes the new version string to `version.txt`.
4. Filament widget flips to "SDE is up-to-date" on the next scheduled
   check (or immediately via `make sde-check`).

## Why not in `app/`?

`version.txt` is infra state, not application code. Keeping it under
`infra/` makes the bind-mount boundary obvious: the app container reads
it, but the app *never writes to it*. Writes happen from the Python
importer side (future), under operator control.

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

**Before the first import** `version.txt` is absent — the Filament
widget on `/admin` shows "No SDE loaded". The `sde_importer` container
(see `python/`) writes this file at the end of a successful load, using
the upstream HTTP ETag by default (or the CCP `buildNumber` as a
fallback when the ETag is missing).

## Bumping the snapshot

```bash
make sde-import
```

That runs a one-shot container from the `tools` compose profile:

1. Downloads the latest SDE JSONL zip from
   `https://developers.eveonline.com/static-data/…` (or whatever
   `SDE_SOURCE_URL` is set to).
2. Opens a single MariaDB transaction, truncates every `ref_*` table,
   streams each of the 56 JSONL files into its matching table in
   batches of 2000 rows.
3. Replaces the `ref_snapshot` row with the new build number + release
   date + ETag.
4. Inserts a `reference.sde_snapshot_loaded` outbox event (consumed by
   Laravel on its side of the plane boundary).
5. `COMMIT`. Only after commit do we write the new pin to `version.txt`
   — so a mid-import crash leaves both the DB and the pin untouched.
6. The Filament widget flips to "Up to date" on the next
   `reference:check-sde-version` run (`make sde-check` to force it now).

Rollback on failure: the whole load is one transaction, so any error
before step 5 triggers `ROLLBACK` and leaves the previous snapshot
intact. `version.txt` is only written on the success path.

## Why not in `app/`?

`version.txt` is infra state, not application code. Keeping it under
`infra/` makes the bind-mount boundary obvious: the app container reads
it, but the app *never writes to it*. Writes happen from the Python
importer side (future), under operator control.

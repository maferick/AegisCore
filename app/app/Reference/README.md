# Reference

Cross-cutting EVE static reference data — the read-only `ref_*` tables
projected from CCP's Static Data Export (SDE), plus the plumbing that
tracks version drift against upstream.

## Not a pillar

`app/Reference/` sits **outside** `app/Domains/` and is **explicitly not
a pillar**. Reference data is consumed by every pillar (a killmail needs
`ref_systems`, a doctrine references `ref_item_types`, a spy-detection
rollup wants `ref_regions`), so it can't sit inside any one of them
without creating cross-pillar coupling.

The parallel is `app/Outbox/`, which is also cross-cutting plumbing that
lives outside the Domains structure.

See [ADR-0001](../../../docs/adr/0001-static-reference-data.md) for the
full data-ownership rationale — MariaDB canonical (`ref_*`), Neo4j as
derived graph projection, OpenSearch deferred to phase 2.

## Layout

```
Reference/
├── Console/     ← artisan commands (version check, future: import, reload)
├── Jobs/        ← Horizon jobs dispatched by Console / scheduled tasks
└── Models/      ← Eloquent models for ref_*  +  sde_version_checks
```

Future additions (as the SDE importer lands):

- `Actions/` — invokables like `ResolveType`, `ResolveSystem` (cached
  id → row resolvers, used from every pillar).
- `Data/` — spatie/laravel-data DTOs (`TypeData`, `SystemData`).
- `Events/` — `SdeSnapshotLoaded` outbox event (emits
  `reference.sde_snapshot_loaded`).

## Cross-pillar access is allowed here

The "no cross-pillar Eloquent relationships" rule from
`app/Domains/README.md` **is relaxed for `app/Reference/`**. Pillars may
hold FKs into `ref_*` tables and hydrate them via Eloquent relations —
that's the whole point of reference data.

What's **not** allowed:

- Laravel writes to `ref_*` tables. Those are loaded by the Python
  `sde_importer` only.
- Laravel calls to ESI for reference data. If a `type_id` is unknown,
  that's an ops problem (reload the snapshot), not a runtime fallback.

## Current scope

Only the version-drift check is implemented today:

- **`Models/SdeVersionCheck`** — one row per daily check.
- **`Jobs/CheckSdeVersion`** — HEADs the pinned SDE tarball URL,
  compares against `/var/www/sde/version.txt`, inserts a row.
- **`Console/CheckSdeVersionCommand`** — dispatches the job; scheduled
  daily from `routes/console.php`. Also runnable on demand via
  `make sde-check`.

The actual `ref_*` tables and the Python importer land in follow-up PRs.

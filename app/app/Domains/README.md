# Domains

AegisCore's Laravel control plane is organized into four **pillars**:

| Pillar | Concern |
|---|---|
| `SpyDetection` | Flagging of alts / leaks / hostile infiltration signals. |
| `BuyallDoctrines` | Fleet doctrine catalogs, fits, buyall stock targets. |
| `KillmailsBattleTheaters` | Killmail ingestion + battle-theater rollups. |
| `UsersCharacters` | Player identity, alt linking, ESI token custody. |

## Why pillars, not layers

Every feature threads through models, actions, events, and projections.
Grouping by *domain* instead of *layer* (all Models in one folder, all
Controllers in another, …) keeps related code together and makes the
plane boundary between Laravel (control) and Python (analytics) visible
at the directory level.

## Per-pillar layout

Each `<Pillar>/` directory uses the same internal structure:

```
<Pillar>/
├── Actions/       ← single-purpose invokable services; entry point for HTTP + queues
├── Data/          ← spatie/laravel-data DTOs (request, response, projection payloads)
├── Events/        ← DomainEvent subclasses (outbox-bound)
├── Models/        ← Eloquent models
└── Projections/   ← write-through shaping for OpenSearch / Influx / Neo4j
```

## Rules

1. **No cross-pillar Eloquent relationships.** Cross reference by
   `aggregate_id` only; load via a Pillar's repository/action, never via
   `$this->otherPillarRelation`.
2. **No direct writes to derived stores from Laravel** (plane boundary).
   Emit a `DomainEvent` via `OutboxRecorder::record()` inside the same
   transaction as the MariaDB write; the Python consumer projects to
   OpenSearch / Neo4j / InfluxDB.
3. **Read-after-write is MariaDB only.** Derived stores are eventually
   consistent — never read back from them in the same request that wrote.
4. **Jobs are <2s and <100 rows** (see `config/aegiscore.php → plane`).
   Anything heavier is decomposed, batched, or handed to Python via the
   outbox.

See also: `../Outbox/` for the plane-boundary plumbing, and
`../../../docs/CONTRACTS.md` for event naming + schema rules.

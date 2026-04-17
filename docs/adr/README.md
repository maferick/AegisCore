# Architecture Decision Records

This directory captures **decisions we've made and don't want to re-litigate**.
Each file records one decision: the context, the call, what we rejected, and
the consequences we accept.

An ADR isn't a plan — it's a tombstone for a question. Once accepted, it's the
reference point for code review ("this is already decided, see ADR-000X").

## When to write one

- A cross-cutting decision that affects >1 pillar or crosses a plane boundary.
- A decision where the obvious answer is wrong and we want to memorialize why.
- Anything that would cause a reviewer to ask "why are we doing it this way?"
  six months from now.

Not every PR needs one. A small refactor, a bug fix, a new Filament resource —
none of those need an ADR. Decisions like "MariaDB is canonical for SDE, not
OpenSearch" do.

## Format

One file per ADR. Filename: `NNNN-kebab-title.md`, zero-padded. Numbers are
assigned on merge, not on draft (to avoid collisions between parallel PRs —
pick the next free number right before merge).

Required sections:

- **Status** — Proposed / Accepted / Superseded by ADR-XXXX / Deprecated
- **Context** — what problem, what constraints
- **Decision** — what we're doing, in one paragraph
- **Alternatives considered** — what we rejected and why
- **Consequences** — positive, negative, neutral; the trade-offs we accept

Keep it short. An ADR that takes more than ten minutes to read is doing
planning work the roadmap should do.

## Index

- [ADR-0001](0001-static-reference-data.md) — SDE static data: MariaDB
  canonical, Neo4j + OpenSearch as derived projections
- [ADR-0002](0002-eve-sso-and-esi-client.md) — EVE SSO for admin access,
  ESI client split across planes
- [ADR-0003](0003-data-placement-freeze.md) — Data placement freeze:
  per-store ownership across the four pillars
- [ADR-0004](0004-market-data-ingest.md) — Market data: dump import,
  live polling, and per-donor structure auth
- [ADR-0005](0005-private-market-hub-overlay.md) — Private Market Hub
  overlay: canonical hubs, collector / viewer split, donor-gated
  intersection rule
- [ADR-0006](0006-battle-theater-reports.md) — Battle Theater reports:
  metric contract and viewer-context side assignment
- [ADR-0007](0007-intel-copilot-rag-broker.md) — Intel Copilot: tool-
  augmented RAG broker over OpenSearch / SQL / Neo4j, with a typed query
  plan IR as the spine

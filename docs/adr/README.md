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

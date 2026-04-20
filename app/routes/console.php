<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| Executed by the `scheduler` compose service, which runs
| `php artisan schedule:work` — a long-running process that invokes
| `schedule:run` every minute inside the container. No host cron required.
|
| Job-placement rule: scheduled tasks must still respect the plane boundary
| (< 2s, < 100 rows). Anything heavier must go through the outbox to Python.
| See AGENTS.md § "Job placement rule".
*/

// Daily drift check against CCP's SDE tarball. Dispatches onto Horizon so
// the one HTTP HEAD + one insert run on the queue (with retries + log
// visibility) rather than inside the scheduler process itself.
Schedule::command('reference:check-sde-version')
    ->dailyAt('08:00')
    ->timezone('UTC')
    ->onOneServer()
    ->name('sde-version-check');

// Donations wallet poll. Dispatches a Horizon job that pulls the latest
// page of the donations character's wallet journal, upserts new
// `player_donation` rows into `eve_donations`, and resolves donor names
// in one batch /universe/names/ call. Cadence comes from
// `EVE_DONATIONS_POLL_CRON` (default `*/5 * * * *`). Idempotent by
// `journal_ref_id` — re-running the same tick is a no-op.
//
// `withoutOverlapping` is a belt-and-braces guard: a 5-minute interval
// against a single ESI page never realistically takes longer than 30s,
// but if a tick ever does stall we don't want a backed-up second tick
// double-refreshing the rotated refresh token in parallel.
//
// See App\Domains\UsersCharacters\Jobs\PollDonationsWallet for the
// plane-boundary reasoning (ADR-0002 § phase-2 amendment).
Schedule::command('eve:poll-donations')
    ->cron((string) config('eve.sso.donations.poll_cron', '*/5 * * * *'))
    ->onOneServer()
    ->withoutOverlapping(10)
    ->name('eve-poll-donations');

// Donor-benefit safety-net recompute.
//
// The poller recomputes per-donor in-line after each upsert, which is
// the primary path — expiry is materialised moments after the wallet
// journal reports a new donation. But that in-line recompute runs in
// the same `handle()` tick AFTER `resolveDonorNames()`; if anything
// between the upsert and the recompute loop throws (names DB update,
// transient connection hiccup), the donation row lands in
// `eve_donations` but the matching `eve_donor_benefits` row never
// gets written. On the next tick the `journal_ref_id` is no longer
// "fresh", so the donor drops out of `$insertedCharacterIds` and the
// missed recompute is never retried — the donor silently loses
// ad-free status until an operator runs the artisan command by hand.
//
// Running the full recompute hourly closes that gap. It's cheap
// (donor base is dozens of characters, each recompute is microseconds)
// and idempotent (recomputing an already-correct row just rewrites
// the same values). Hourly cadence keeps the log noise down while
// still repairing orphaned benefits within one tick's blast radius
// for any observer.
Schedule::command('eve:donations:recompute')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(10)
    ->name('eve-donations-recompute');

// Daily corp + alliance standings sync for every donor with a market
// token. Walks each EveMarketToken, resolves the character's current
// corp/alliance affiliation, pulls the corp and alliance contact
// lists from ESI, and upserts rows into `character_standings`.
//
// Cadence: daily at 04:00 UTC — off-peak for most EVE TZs and well
// clear of the donations poller's 5-minute cadence so we don't stack
// token refreshes. Corp/alliance standings change on human timescales
// (days between edits), so daily is ample; donors who need fresher
// data use the "Sync standings now" button on /account/settings.
//
// `withoutOverlapping(30)` guards against a long-running sync (ESI
// degraded, rate-limit backoff) bleeding into the next scheduled
// tick. 30-minute lock release matches the job's 10-minute timeout
// with a generous headroom.
//
// See App\Domains\UsersCharacters\Jobs\SyncDonorStandings for the
// per-donor loop and the plane-boundary reasoning (ADR-0002 §
// phase-2 amendment).
Schedule::command('eve:sync-standings')
    ->dailyAt('04:00')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(30)
    ->name('eve-sync-standings');

// Daily corporation affiliation sweep. Pulls current alliance + full
// alliance history from ESI for every corp that might show up as a
// classification target — corps appearing in character_standings,
// linked characters, coalition_entity_labels, or viewer_contexts.
//
// Populates `corporation_affiliation_profiles` so the resolver's step
// 5 (current-alliance inheritance) and step 6 (previous-alliance
// history) can actually fire. Without this sweep those steps silently
// skip and the resolver falls through to fallback on every corporation
// target that isn't directly tagged with a coalition label.
//
// Cadence: daily at 04:30 UTC — 30 minutes after the standings sync
// so the two don't stack. Corp alliance moves happen on day-scale
// human timeframes, so daily keeps the table within one business-EVE
// cycle of live. On-demand refresh: `php artisan
// classification:sync-corp-affiliations --sync`.
//
// Only stale rows actually hit ESI (staleness window is
// `classification.corp_affiliation_staleness_hours`, default 24h), so
// a second tick in the same day is a near-no-op: the query planner
// filters everything out before the ESI client even gets a path.
//
// See App\Domains\UsersCharacters\Jobs\SyncCorporationAffiliations for
// the "which corps to sync" union and the per-corp failure isolation.
Schedule::command('classification:sync-corp-affiliations')
    ->dailyAt('04:30')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(30)
    ->name('classification-sync-corp-affiliations');

// Nightly stale-classification sweep + recompute dispatch.
//
// Phase 1: finds active viewers whose `last_recomputed_at` is older than
// the staleness window (classification.recompute.staleness_days, default
// 7 days) and marks their cached classification rows `is_dirty=1`.
//
// Phase 2 (--dispatch): for each distinct viewer with dirty rows,
// dispatches a RecomputeDirtyClassifications job onto Horizon. Each job
// is per-viewer and capped at `classification.recompute.max_dirty_per_viewer`
// rows (default 50), keeping it within the plane boundary.
//
// Cadence: daily at 05:00 UTC — after the standings sync (04:00) and
// corp-affiliation sync (04:30) have finished writing fresh upstream data.
// The recompute then runs against up-to-date evidence.
//
// `withoutOverlapping(30)` guards against a long-running mark pass
// (e.g. thousands of viewers) stacking with the next scheduled tick.
Schedule::command('classification:sweep-stale --dispatch')
    ->dailyAt('05:00')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(30)
    ->name('classification-sweep-stale');

// Killmail enrichment — drain the unenriched backlog in parallel.
//
// Dispatches one enrichment job per unenriched month every 5 minutes.
// Each month runs as its own ShouldBeUnique chain across Horizon's
// worker pool (5 workers in prod), so up to 5 months process in
// parallel. Each job self-dispatches until its month is done.
//
// The 5-minute cadence restarts any broken self-dispatch chains and
// picks up newly ingested months.
Schedule::command('killmails:enrich')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10)
    ->name('killmails-enrich');

// Entity name resolution — populate esi_entity_names cache from
// killmail participants independently of the enrichment pipeline.
//
// Dispatches ResolveEntityNames which batch-resolves uncached entity
// IDs via ESI /universe/names/ (rate-limited through the shared ESI
// client). Self-dispatches until all participant IDs are cached.
//
// Runs every 2 minutes; the job is ShouldBeUnique so overlaps are
// silently skipped.
Schedule::job(new \App\Domains\KillmailsBattleTheaters\Jobs\ResolveEntityNames)
    ->everyTwoMinutes()
    ->onOneServer()
    ->name('resolve-entity-names');

// Character corporation history — fetch full corp membership timelines
// from ESI for characters observed in killmails. Public endpoint,
// unauthed, cached 1 day by CCP. Used for event-time affiliation
// snapshots ("what corp was this pilot in when the killmail happened").
//
// Processes 50 characters per dispatch, self-dispatches until caught
// up. Rate-limited through the shared ESI client.
// 8 shards = 8 concurrent fetch jobs draining disjoint slices of the
// uncached character set. Each shard is ShouldBeUnique on its own id,
// so re-firing on the 5-min tick is a no-op if the shard is still
// running; scheduler pressure stays flat while throughput multiplies.
foreach (range(0, 7) as $shardId) {
    Schedule::job(new \App\Domains\KillmailsBattleTheaters\Jobs\FetchCharacterCorporationHistory($shardId, 8))
        ->everyFiveMinutes()
        ->onOneServer()
        ->name("fetch-corp-history:{$shardId}");
}

// Corporation alliance history — same pattern, one level up. Answers
// "which alliance was this corp in at time Y?" for killmail detail
// event-time rendering. Smaller numerator than the character set
// (<100k player corps vs 500k+ pilots); backfill closes in a day or
// two. Staggered :02 past every 5 minutes so the two history jobs
// don't collide on the queue.
Schedule::job(new \App\Domains\KillmailsBattleTheaters\Jobs\FetchCorporationAllianceHistory)
    ->cron('2-59/5 * * * *')
    ->onOneServer()
    ->name('fetch-corp-alliance-history');

// Factional-warfare enlistment snapshot. ESI /corporations/{id}/fw/stats/
// is a public endpoint with 1-hour CCP caching; 7-day TTL on our side
// keeps pressure minimal. Fires hourly on :07 so it doesn't land on the
// same tick as the two history jobs above.
Schedule::job(new \App\Domains\KillmailsBattleTheaters\Jobs\FetchCorporationFwEnlistment)
    ->cron('7 * * * *')
    ->onOneServer()
    ->name('fetch-corp-fw-enlistment');

// Allegiance graph backfill — project the last 24h of locked battle
// theaters into the Neo4j allegiance graph so the resolver's
// historical-allegiance tiebreaker accumulates signal on its own
// (not just from operator overrides). Incremental: runs against the
// last 24h every 6h so a single crash / deploy window never misses
// more than one cycle of data, and the upsert is idempotent anyway.
Schedule::command('allegiance:backfill --since=24h')
    ->cron('0 */6 * * *')
    ->onOneServer()
    ->withoutOverlapping(60)
    ->name('allegiance-backfill');

// zkill catch-up — fans out a per-system job for every system that
// had killmail activity in the last 4h. Each job asks zkill for
// kills it might have that our R2Z2 stream missed (big-fight feed
// backlog, dropped sequences, etc) and ingests them through the
// same KillmailIngested outbox event the stream uses. Every 3h,
// off the :00/:30 rush so we don't collide with the mariadb backup.
Schedule::command('killmails:zkill-catchup')
    ->cron('17 */3 * * *')
    ->onOneServer()
    ->withoutOverlapping(60)
    ->name('zkill-catchup');

// Spec 7 nightly refresh of character_role_historical_priors from
// the last 90 days of Spec 4 feature rows + Spec 5 inferred-role
// frequency + Spec 6 attestations. Cold-start pilots (<5 battles)
// are pruned so only characters with meaningful signal get priors.
Schedule::command('battle:refresh-priors')
    ->dailyAt('03:15')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(120)
    ->name('refresh-character-role-priors');

// Spec 8 role-tied doctrine auto-detector. Runs 45 minutes after
// the priors refresh so Spec 5 re-runs triggered by new priors
// have time to settle.
Schedule::command('battle:compute-auto-doctrines')
    ->dailyAt('04:00')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(120)
    ->name('compute-auto-doctrines');

// Per-killmail pilot role tags. Cheap join on hull-category mapping,
// runs hourly so new killmails get a role tag within the hour and
// ship_class_category_mapping updates propagate the same day.
Schedule::command('killmails:compute-pilot-roles')
    ->hourlyAt(22)
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(30)
    ->name('killmail-pilot-roles');

// Derive daily market_history rollups from our per-minute market_orders
// snapshots. Runs hourly so yesterday's row refreshes as the day fills
// out, and today's partial row gains freshness between EveRef's
// multi-day-lagged canonical dump. Source='esi_derived_daily' keeps
// these rows distinguishable from EveRef-sourced backfill.
Schedule::command('market:derive-daily')
    ->hourlyAt(47)
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(30)
    ->name('market-derive-daily');

// NB: battle:process-pending is NOT scheduled here — it shells
// out to `docker compose run` for each (battle, alliance) pair,
// and the Laravel scheduler container has no docker CLI / no
// mounted docker socket. Invoke from the host via
// `make battle-process-pending` (cron example in Makefile).

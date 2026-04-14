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

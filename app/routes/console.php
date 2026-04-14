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

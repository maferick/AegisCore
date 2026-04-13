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

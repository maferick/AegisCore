<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Laravel Horizon config
|--------------------------------------------------------------------------
|
| Why this file exists (keep this paragraph — it's load-bearing context):
|
| Horizon's vendored default config only declares supervisors for two
| environments: `production` and `local`. AegisCore runs with
| `APP_ENV=dev` (from `AEGISCORE_ENV=dev` — see .env.example + compose),
| and the staging / prod stacks use `staging` and `prod`. None of those
| strings match the vendor defaults, so without this file Horizon's
| master process starts, registers no supervisors, spawns zero worker
| processes, and dispatched `ShouldQueue` jobs pile up in Redis
| forever with no consumer.
|
| Symptom in the /horizon dashboard: "Status: Active" in the header,
| but the supervisor row is empty and Total Processes = 0. Scheduled
| jobs show up in "Jobs Past Hour" (they were dispatched) but never
| complete. First-order casualties so far: the daily SDE version
| check and the 5-minute donations wallet poller.
|
| This file fixes that by listing a supervisor for every APP_ENV we
| actually deploy under. The supervisor itself is vanilla — one
| auto-balanced pool on the `default` queue against the `redis`
| connection (matching QUEUE_CONNECTION=redis). Process counts are
| per-environment so dev doesn't waste RAM and prod can actually
| work.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | null → serve Horizon under the application's primary hostname. We
    | serve it at /horizon, which is gated by the `web` + `auth` middleware
    | stack + the `viewHorizon` gate defined in App\Providers\HorizonServiceProvider
    | (delegates to User::canAccessPanel — same rule as Filament /admin).
    |
    */
    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    */
    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | Name of the Redis connection (from config/database.php `redis`
    | block) Horizon uses for its own bookkeeping. We use the `default`
    | connection — no separate Horizon-only Redis instance. If that ever
    | becomes a scaling concern, add a `horizon` connection in
    | config/database.php and point this at it.
    |
    */
    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix applied to every Redis key Horizon writes. APP_NAME is
    | folded in so two AegisCore stacks sharing a Redis instance
    | (unusual, but operationally possible in CI) don't step on each
    | other's supervisor state.
    |
    */
    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'laravel'), '_').'_horizon:',
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | Intentionally overridden at runtime by App\Providers\HorizonServiceProvider::register()
    | to ['web', 'auth'] so unauth hits redirect to login (see
    | bootstrap/app.php's redirectGuestsTo) instead of 403'ing at the gate.
    | Value here is a fallback only — if the app provider stops overriding
    | it, this at least still gates the dashboard.
    |
    */
    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | "Long wait" threshold in seconds — per connection:queue tuple.
    | Horizon surfaces an alert on the dashboard when a queue crosses
    | this number. 60s is the vendor default and is fine here: the
    | jobs we dispatch (donations poll, SDE check) each run in
    | single-digit seconds; a minute of wait indicates real backpressure,
    | not just fluctuation.
    |
    */
    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming
    |--------------------------------------------------------------------------
    |
    | How long Horizon keeps each class of job entry in Redis before
    | trimming. Values in minutes. Vendor defaults — we don't generate
    | enough volume to need tuning.
    |
    */
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | FQCN list of jobs whose completed entries should NOT be stored —
    | useful for high-volume broadcast jobs that would flood the
    | dashboard. Empty today; revisit once we have a job type worth
    | silencing.
    |
    */
    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | How many snapshots Horizon keeps per job class / queue for the
    | Metrics page. Vendor default (24 hours of hourly snapshots).
    |
    */
    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | false → wait for in-flight jobs to finish before exiting on
    | SIGTERM. Docker's 10s stop-grace window is enough for our jobs
    | (single-digit seconds), and partial-completion is the category of
    | failure we're trying to avoid.
    |
    */
    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Master Supervisor Memory Limit
    |--------------------------------------------------------------------------
    |
    | Megabytes. 64 is the vendor default and is comfortable for the
    | master process itself (individual workers have their own limit
    | under `defaults.*.memory` below).
    |
    */
    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Defaults
    |--------------------------------------------------------------------------
    |
    | Base supervisor spec every environment extends. One auto-balanced
    | pool on the `default` queue against the `redis` connection
    | (matches QUEUE_CONNECTION=redis + REDIS_QUEUE=default). When we
    | grow a dedicated high-priority queue, add a second supervisor
    | block here rather than splitting this one.
    |
    */
    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            // `auto` is the right default while jobs are heterogeneous
            // — Horizon shifts processes between queues based on
            // observed load. Revisit if we grow a latency-sensitive
            // queue that shouldn't have to compete for processes.
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            // Megabytes per worker process. 512 covers the enrichment
            // batch (2000 killmails with eager-loaded items + attackers).
            'memory' => 512,
            // One try per dispatch. Scheduled jobs get their retry
            // from the next tick; ad-hoc dispatches that need retries
            // should opt in per-job via `$tries` on the class.
            'tries' => 1,
            // Seconds. The donations poller declares `public int $timeout = 60`
            // on the class; this is the supervisor's own ceiling.
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment-specific Overrides
    |--------------------------------------------------------------------------
    |
    | IMPORTANT: the keys here MUST match the value of APP_ENV at
    | runtime — Horizon only spawns supervisors whose environment key
    | matches. AegisCore maps AEGISCORE_ENV {dev, staging, prod} →
    | APP_ENV directly (see infra/docker-compose.yml). `production` and
    | `local` are kept as fallbacks for operators who set APP_ENV
    | manually to the stock Laravel values; dropping them would make a
    | naïve `APP_ENV=production` deployment silently spawn no workers.
    |
    | maxProcesses is the only knob that varies by env for now — job
    | shape and queue layout are the same everywhere. Dev stays small
    | (1) to keep the laptop quiet; staging and production scale up.
    |
    */
    'environments' => [
        'dev' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],
        'staging' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],
        'prod' => [
            'supervisor-1' => [
                'maxProcesses' => 5,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],
        // Vendor-default fallbacks — in case someone sets APP_ENV to
        // the stock Laravel values directly (e.g. an operator testing
        // with `APP_ENV=production php artisan horizon`).
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 5,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],
        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 1,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],
    ],

];

<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Four-layer defence against the 2026-04-16 production MariaDB wipe.
     *
     * The incident: a `make test` invocation dropped every table in
     * production. Chain:
     *   1. phpunit.xml declared DB_CONNECTION=sqlite / DB_DATABASE=:memory:
     *      WITHOUT force="true". The php-fpm container had
     *      DB_CONNECTION=mariadb + DB_DATABASE=aegiscore injected by
     *      infra/docker-compose.yml, and phpunit's <env> only sets via
     *      putenv() when unset, so the container values won.
     *   2. The generic `mariadb` connection in config/database.php reads
     *      database/host/user/pass from env() — so it pointed at the
     *      production aegiscore schema.
     *   3. The `DatabaseMigrations` trait on every test ran
     *      migrate:fresh against that default connection and dropped
     *      every production table.
     *
     * The layered fix:
     *
     *   L1. phpunit.xml — force="true" on every DB_* variable, pinning
     *       DB_CONNECTION=testing_mariadb + DB_DATABASE=aegiscore_test.
     *   L2. config/database.php — a new `testing_mariadb` connection
     *       with NO env() calls. Its database is literally
     *       `aegiscore_test`, a separate schema from production. Host,
     *       port, user, and password are hardcoded to the infra
     *       defaults. An env leak cannot redirect it.
     *   L3. The assertion below refuses to proceed unless DB_CONNECTION
     *       resolves to testing_mariadb. The same check also runs in
     *       tests/bootstrap.php before the migrate:fresh fires, so
     *       the suite aborts on misconfig before any DDL executes.
     *   L4. tests/bootstrap.php runs schema build via
     *       `Artisan::call('migrate:fresh', ['--database' => 'testing_mariadb'])`
     *       ONCE per phpunit invocation. The --database flag pins the
     *       migrator's target connection by name; migrate:fresh can
     *       only ever target aegiscore_test, never aegiscore.
     *
     * Tests then use `DatabaseTransactions` (per-test BEGIN/ROLLBACK)
     * to isolate changes. `DatabaseMigrations` is used only where a
     * test specifically needs `transactionLevel() == 0` at entry
     * (OutboxRecorderTest) — even there, its migrate:fresh inherits
     * the default=testing_mariadb pinning from bootstrap.php, so it
     * can only wipe the test schema.
     *
     * The one-time schema + grants provisioning for aegiscore_test is
     * documented in `scripts/setup-test-db.sh` (or: `make test-db-setup`).
     */
    protected function setUp(): void
    {
        // $_SERVER wins — Laravel's env() reads the ServerConstAdapter
        // before EnvConstAdapter / getenv(). The 2026-04-16 wipe
        // happened because phpunit's <env> overrode $_ENV + putenv but
        // left $_SERVER untouched, so `env('DB_CONNECTION')` returned
        // the docker-compose-injected 'mariadb' and routed migrate:fresh
        // at production. phpunit.xml now sets <server> entries too;
        // this guard checks all three sources in the correct priority
        // order so a future misconfig surfaces immediately.
        $connection = (string) (
            $_SERVER['DB_CONNECTION']
            ?? $_ENV['DB_CONNECTION']
            ?? (getenv('DB_CONNECTION') ?: '')
        );
        if ($connection !== 'testing_mariadb') {
            throw new RuntimeException(sprintf(
                'REFUSING to run tests: DB_CONNECTION=%s (must be "testing_mariadb"). '
                .'See app/tests/TestCase.php for the 2026-04-16 production-wipe this guard prevents.',
                $connection === '' ? '(unset)' : $connection,
            ));
        }

        parent::setUp();

        // Belt-and-braces — if any service provider or bootstrapper
        // flipped the default connection after refreshApplication()
        // loaded config, snap it back so test code defaults to the
        // isolated schema rather than whatever is in env.
        if ((string) config('database.default') !== 'testing_mariadb') {
            config(['database.default' => 'testing_mariadb']);
            DB::purge();
        }
    }

    /**
     * L4 — explicit pin on the migrate:fresh CLI flag. Overrides
     * `CanConfigureMigrationCommands::migrateFreshUsing()` to append
     * `--database=testing_mariadb`. Without this, DatabaseMigrations
     * would run migrate:fresh against whatever the default connection
     * happens to be at the moment the trait fires — which on 2026-04-16
     * resolved to the env-derived `mariadb` (production) and wiped it.
     * With the flag, the migrator's target is pinned by name
     * regardless of default-connection drift.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing()
    {
        return array_merge(
            parent::migrateFreshUsing(),
            ['--database' => 'testing_mariadb'],
        );
    }
}

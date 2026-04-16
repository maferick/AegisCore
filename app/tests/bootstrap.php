<?php

declare(strict_types=1);

/*
 * phpunit bootstrap for AegisCore.
 *
 * Pre-flight defences against the 2026-04-16 production MariaDB wipe.
 * See tests/TestCase.php for the full post-mortem; this file is the
 * outermost ring of L3 — it fires before a single test class is
 * loaded, so a misconfigured suite aborts before any Laravel
 * container boot, any migrator call, and any DatabaseMigrations
 * trait hook.
 */

require __DIR__.'/../vendor/autoload.php';

// DB_CONNECTION must be testing_mariadb. phpunit.xml force="true"
// should have set this; if it isn't set, the L2 hardcoded connection
// in config/database.php and the L3 guard in TestCase.php are the
// remaining safety nets — but we abort here to surface the
// misconfiguration immediately instead of letting the suite limp on.
// $_SERVER first — that's what Laravel's env() reads, and the
// 2026-04-16 wipe chain turned on $_SERVER['DB_CONNECTION']='mariadb'
// surviving the phpunit <env> override. Check every source.
$connection = (string) (
    $_SERVER['DB_CONNECTION']
    ?? $_ENV['DB_CONNECTION']
    ?? (getenv('DB_CONNECTION') ?: '')
);
if ($connection !== 'testing_mariadb') {
    fwrite(STDERR, sprintf(
        "REFUSING to bootstrap phpunit: DB_CONNECTION=%s (must be \"testing_mariadb\").\n"
        ."See app/tests/TestCase.php for the 2026-04-16 production-wipe this guard prevents.\n",
        $connection === '' ? '(unset)' : $connection,
    ));
    exit(1);
}

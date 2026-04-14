<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AegisCore runtime config
|--------------------------------------------------------------------------
|
| Single source of truth for derived-store connections and plane policy.
| Domain code resolves connection details through config('aegiscore.*')
| rather than calling env() directly — env() is not available once the
| config cache is built in production.
|
| See docs/ARCHITECTURE.md for the Laravel ↔ Python plane boundary.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Environment label
    |--------------------------------------------------------------------------
    | Mirrors AEGISCORE_ENV from the compose file. Surfaced in API responses
    | and metrics tags.
    */
    'env' => env('AEGISCORE_ENV', 'dev'),

    /*
    |--------------------------------------------------------------------------
    | OpenSearch
    |--------------------------------------------------------------------------
    | Phase 1: security plugin disabled, plain HTTP on the internal network.
    | Clients should use opensearch-project/opensearch-php and read these
    | values via config('aegiscore.opensearch.*').
    */
    'opensearch' => [
        'host' => env('OPENSEARCH_HOST', 'http://opensearch:9200'),
        'verify' => false, // No TLS in phase 1.
    ],

    /*
    |--------------------------------------------------------------------------
    | InfluxDB 2.x
    |--------------------------------------------------------------------------
    */
    'influxdb' => [
        'host' => env('INFLUXDB_HOST', 'http://influxdb2:8086'),
        'token' => env('INFLUXDB_TOKEN'),
        'org' => env('INFLUXDB_ORG', 'aegiscore'),
        'bucket' => env('INFLUXDB_BUCKET', 'primary'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Neo4j (Bolt protocol)
    |--------------------------------------------------------------------------
    */
    'neo4j' => [
        'host' => env('NEO4J_HOST', 'bolt://neo4j:7687'),
        'user' => env('NEO4J_USER', 'neo4j'),
        'password' => env('NEO4J_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Docker monitoring
    |--------------------------------------------------------------------------
    | URL for the scoped read-only Docker API the /admin/container-status
    | page queries. Points at the `docker_socket_proxy` service in
    | infra/docker-compose.yml (tecnativa/docker-socket-proxy), which
    | sits in front of /var/run/docker.sock and exposes only GET
    | /containers* + /info + /version.
    |
    | Empty host disables the admin page entirely — the service returns
    | a single "not configured" card so operators who'd rather not expose
    | even a read-only slice of the socket can opt out by leaving
    | DOCKER_API_HOST unset (or removing the proxy service from the
    | compose file).
    |
    | Timeout is deliberately tight: the widget polls every ~10s and a
    | dead proxy shouldn't hold the page past its own cache TTL.
    */
    'docker' => [
        'host' => env('DOCKER_API_HOST', ''),
        'timeout_seconds' => (int) env('DOCKER_API_TIMEOUT_SECONDS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Plane boundary
    |--------------------------------------------------------------------------
    | Hard limits for Laravel's control plane. Jobs that exceed these
    | thresholds must be re-shaped, batched, or emitted via the outbox
    | for the Python analytics plane to handle.
    |
    | See AGENTS.md § "Laravel ↔ Python plane boundary".
    */
    'plane' => [
        'max_job_duration_seconds' => 2,
        'max_job_rows' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | EVE Static Data (SDE)
    |--------------------------------------------------------------------------
    | Upstream SDE tarball URL (pinned JSONL-zip from CCP's developer site)
    | and the on-disk path to the currently pinned version marker. The daily
    | `reference:check-sde-version` command HEADs the upstream URL, reads the
    | local marker, and records both in `sde_version_checks` so the admin
    | widget can show "you're N days / Last-Modified behind".
    |
    | The actual SDE importer (Python, `make sde-import`) is scoped to a
    | later PR — this config only drives the version-drift check.
    |
    | See docs/adr/0001-static-reference-data.md for the data-ownership
    | rationale (MariaDB canonical, Neo4j + OpenSearch as derived projections).
    */
    'sde' => [
        'source_url' => env(
            'SDE_SOURCE_URL',
            'https://developers.eveonline.com/static-data/eve-online-static-data-latest-jsonl.zip',
        ),
        // In-container path for the pinned version marker. `infra/sde/` is
        // bind-mounted read-only at /var/www/sde in both php-fpm and
        // scheduler. Missing / empty file = no snapshot loaded yet, which
        // the widget surfaces as "SDE not loaded".
        'version_file' => env('SDE_VERSION_FILE', '/var/www/sde/version.txt'),
        // HTTP client timeout for the daily HEAD check. Keep tight — this
        // runs inside the plane-boundary < 2s budget.
        'check_timeout_seconds' => 10,
    ],

];

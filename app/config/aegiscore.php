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

];

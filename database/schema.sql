-- AegisCore Canonical Schema Definition
-- This file is the ONLY source of truth for database structure (Section 2.3).
-- No code may assume fields absent from this schema.
-- Every schema change must pass migration + compatibility validation before runtime adoption.

-- ============================================================================
-- PLATFORM CORE TABLES
-- ============================================================================

-- Job execution run log
CREATE TABLE IF NOT EXISTS job_executions (
    id              BIGSERIAL PRIMARY KEY,
    run_id          UUID NOT NULL UNIQUE,
    job_key         VARCHAR(128) NOT NULL,
    execution_mode  VARCHAR(20) NOT NULL CHECK (execution_mode IN ('worker', 'scheduler', 'cli')),
    started_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at    TIMESTAMPTZ,
    outcome         VARCHAR(20) CHECK (outcome IN ('success', 'failure', 'partial')),
    batch_size      INTEGER NOT NULL DEFAULT 0,
    total_batches   INTEGER NOT NULL DEFAULT 0,
    rows_processed  INTEGER NOT NULL DEFAULT 0,
    duration_ms     INTEGER,
    error_type      VARCHAR(30) CHECK (error_type IN ('transient', 'permanent', 'data_quality', 'contract_violation')),
    error_message   TEXT,
    checkpoint_final TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_job_executions_job_key ON job_executions (job_key);
CREATE INDEX IF NOT EXISTS idx_job_executions_started_at ON job_executions (started_at DESC);
CREATE INDEX IF NOT EXISTS idx_job_executions_outcome ON job_executions (outcome);

-- Job checkpoint persistence for resumable execution
CREATE TABLE IF NOT EXISTS job_checkpoints (
    id              BIGSERIAL PRIMARY KEY,
    job_key         VARCHAR(128) NOT NULL UNIQUE,
    checkpoint_type VARCHAR(30) NOT NULL CHECK (checkpoint_type IN ('id_cursor', 'timestamp_watermark', 'composite_cursor')),
    checkpoint_value TEXT NOT NULL,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_job_checkpoints_job_key ON job_checkpoints (job_key);

-- Job registry metadata (PHP-readable projection of authoritative Python registry)
CREATE TABLE IF NOT EXISTS job_registry_metadata (
    id              BIGSERIAL PRIMARY KEY,
    job_key         VARCHAR(128) NOT NULL UNIQUE,
    processor_id    VARCHAR(256) NOT NULL,
    tier            SMALLINT NOT NULL CHECK (tier IN (1, 2, 3)),
    owner           VARCHAR(128) NOT NULL,
    description     TEXT NOT NULL DEFAULT '',
    enabled         BOOLEAN NOT NULL DEFAULT TRUE,
    schedule_cron   VARCHAR(128),
    batch_size      INTEGER NOT NULL,
    max_duration_s  INTEGER NOT NULL,
    checkpoint_type VARCHAR(30) NOT NULL,
    tags            JSONB NOT NULL DEFAULT '[]',
    synced_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Immutable audit trail for config changes and dispatch actions
CREATE TABLE IF NOT EXISTS audit_log (
    id              BIGSERIAL PRIMARY KEY,
    event_type      VARCHAR(64) NOT NULL,
    actor           VARCHAR(128) NOT NULL,
    target          VARCHAR(256),
    payload         JSONB,
    ip_address      INET,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_audit_log_event_type ON audit_log (event_type);
CREATE INDEX IF NOT EXISTS idx_audit_log_actor ON audit_log (actor);
CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log (created_at DESC);

-- Modular platform settings grouped by feature area
CREATE TABLE IF NOT EXISTS settings (
    id              BIGSERIAL PRIMARY KEY,
    module          VARCHAR(64) NOT NULL,
    key             VARCHAR(128) NOT NULL,
    value           TEXT NOT NULL,
    value_type      VARCHAR(20) NOT NULL DEFAULT 'string' CHECK (value_type IN ('string', 'integer', 'float', 'boolean', 'json')),
    description     TEXT NOT NULL DEFAULT '',
    updated_by      VARCHAR(128),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (module, key)
);

CREATE INDEX IF NOT EXISTS idx_settings_module ON settings (module);

-- External adapter state tracking
CREATE TABLE IF NOT EXISTS adapter_state (
    id              BIGSERIAL PRIMARY KEY,
    adapter_name    VARCHAR(64) NOT NULL UNIQUE,
    last_cursor     TEXT,
    last_success_at TIMESTAMPTZ,
    last_error_at   TIMESTAMPTZ,
    error_count     INTEGER NOT NULL DEFAULT 0,
    metadata        JSONB NOT NULL DEFAULT '{}',
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Dispatch queue: PHP control plane writes, Python workers consume
CREATE TABLE IF NOT EXISTS dispatch_queue (
    id              BIGSERIAL PRIMARY KEY,
    job_key         VARCHAR(128) NOT NULL,
    requested_by    VARCHAR(128) NOT NULL,
    parameters      JSONB NOT NULL DEFAULT '{}',
    reason          TEXT,
    status          VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'claimed', 'completed', 'failed')),
    claimed_at      TIMESTAMPTZ,
    completed_at    TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_dispatch_queue_status ON dispatch_queue (status) WHERE status = 'pending';
CREATE INDEX IF NOT EXISTS idx_dispatch_queue_job_key ON dispatch_queue (job_key);

-- ============================================================================
-- DOMAIN TABLES (EVE Online data)
-- ============================================================================

-- Characters (ESI primary, EveWho supplemental)
CREATE TABLE IF NOT EXISTS characters (
    character_id    BIGINT PRIMARY KEY,
    name            VARCHAR(256) NOT NULL,
    corporation_id  BIGINT,
    alliance_id     BIGINT,
    security_status DOUBLE PRECISION,
    source          VARCHAR(20) NOT NULL DEFAULT 'esi',
    last_updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_characters_corporation ON characters (corporation_id);
CREATE INDEX IF NOT EXISTS idx_characters_alliance ON characters (alliance_id);

-- Corporations (ESI primary)
CREATE TABLE IF NOT EXISTS corporations (
    corporation_id  BIGINT PRIMARY KEY,
    name            VARCHAR(256) NOT NULL,
    ticker          VARCHAR(10),
    alliance_id     BIGINT,
    member_count    INTEGER,
    ceo_id          BIGINT,
    source          VARCHAR(20) NOT NULL DEFAULT 'esi',
    last_updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_corporations_alliance ON corporations (alliance_id);

-- Alliances (ESI primary)
CREATE TABLE IF NOT EXISTS alliances (
    alliance_id     BIGINT PRIMARY KEY,
    name            VARCHAR(256) NOT NULL,
    ticker          VARCHAR(10),
    executor_corp_id BIGINT,
    member_count    INTEGER,
    source          VARCHAR(20) NOT NULL DEFAULT 'esi',
    last_updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Killmails (zKill primary event stream)
CREATE TABLE IF NOT EXISTS killmails (
    killmail_id     BIGINT PRIMARY KEY,
    killmail_hash   VARCHAR(64) NOT NULL,
    solar_system_id INTEGER NOT NULL,
    victim_id       BIGINT,
    victim_corp_id  BIGINT,
    victim_alliance_id BIGINT,
    victim_ship_type_id INTEGER,
    damage_taken    INTEGER,
    attacker_count  INTEGER,
    kill_time       TIMESTAMPTZ NOT NULL,
    zkb_total_value DOUBLE PRECISION,
    source          VARCHAR(20) NOT NULL DEFAULT 'zkill',
    ingested_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_killmails_kill_time ON killmails (kill_time DESC);
CREATE INDEX IF NOT EXISTS idx_killmails_victim ON killmails (victim_id);
CREATE INDEX IF NOT EXISTS idx_killmails_system ON killmails (solar_system_id);
CREATE INDEX IF NOT EXISTS idx_killmails_ingested ON killmails (ingested_at DESC);

-- Killmail attackers (normalized from killmail payloads)
CREATE TABLE IF NOT EXISTS killmail_attackers (
    id              BIGSERIAL PRIMARY KEY,
    killmail_id     BIGINT NOT NULL REFERENCES killmails (killmail_id),
    attacker_id     BIGINT,
    corporation_id  BIGINT,
    alliance_id     BIGINT,
    ship_type_id    INTEGER,
    weapon_type_id  INTEGER,
    damage_done     INTEGER,
    final_blow      BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE INDEX IF NOT EXISTS idx_killmail_attackers_killmail ON killmail_attackers (killmail_id);
CREATE INDEX IF NOT EXISTS idx_killmail_attackers_attacker ON killmail_attackers (attacker_id);

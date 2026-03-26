"""Parameterized query helpers for AegisCore database operations.

All SQL in this module references ONLY columns present in database/schema.sql.
This module exists to centralize query definitions for schema conformance checking.
"""

from datetime import datetime
from typing import Any, Optional
from uuid import UUID


# -- Job Executions ----------------------------------------------------------

INSERT_JOB_EXECUTION = """
    INSERT INTO job_executions (
        run_id, job_key, execution_mode, started_at, batch_size
    ) VALUES (%s, %s, %s, %s, %s)
    RETURNING id
"""

UPDATE_JOB_EXECUTION_COMPLETE = """
    UPDATE job_executions SET
        completed_at = %s,
        outcome = %s,
        total_batches = %s,
        rows_processed = %s,
        duration_ms = %s,
        error_type = %s,
        error_message = %s,
        checkpoint_final = %s
    WHERE run_id = %s
"""

SELECT_RECENT_EXECUTIONS = """
    SELECT run_id, job_key, execution_mode, started_at, completed_at,
           outcome, rows_processed, duration_ms, error_type, error_message
    FROM job_executions
    WHERE job_key = %s
    ORDER BY started_at DESC
    LIMIT %s
"""


# -- Job Checkpoints ---------------------------------------------------------

SELECT_CHECKPOINT = """
    SELECT checkpoint_type, checkpoint_value, updated_at
    FROM job_checkpoints
    WHERE job_key = %s
"""

UPSERT_CHECKPOINT = """
    INSERT INTO job_checkpoints (job_key, checkpoint_type, checkpoint_value, updated_at)
    VALUES (%s, %s, %s, %s)
    ON CONFLICT (job_key) DO UPDATE SET
        checkpoint_type = EXCLUDED.checkpoint_type,
        checkpoint_value = EXCLUDED.checkpoint_value,
        updated_at = EXCLUDED.updated_at
"""

DELETE_CHECKPOINT = """
    DELETE FROM job_checkpoints WHERE job_key = %s
"""


# -- Audit Log ---------------------------------------------------------------

INSERT_AUDIT_EVENT = """
    INSERT INTO audit_log (event_type, actor, target, payload, ip_address)
    VALUES (%s, %s, %s, %s, %s)
"""

SELECT_AUDIT_EVENTS = """
    SELECT id, event_type, actor, target, payload, created_at
    FROM audit_log
    WHERE (%s IS NULL OR event_type = %s)
      AND (%s IS NULL OR actor = %s)
      AND created_at >= %s
    ORDER BY created_at DESC
    LIMIT %s
"""


# -- Settings ----------------------------------------------------------------

SELECT_SETTINGS_BY_MODULE = """
    SELECT key, value, value_type, description, updated_by, updated_at
    FROM settings
    WHERE module = %s
    ORDER BY key
"""

UPSERT_SETTING = """
    INSERT INTO settings (module, key, value, value_type, description, updated_by, updated_at)
    VALUES (%s, %s, %s, %s, %s, %s, NOW())
    ON CONFLICT (module, key) DO UPDATE SET
        value = EXCLUDED.value,
        value_type = EXCLUDED.value_type,
        description = EXCLUDED.description,
        updated_by = EXCLUDED.updated_by,
        updated_at = NOW()
"""


# -- Dispatch Queue ----------------------------------------------------------

INSERT_DISPATCH_REQUEST = """
    INSERT INTO dispatch_queue (job_key, requested_by, parameters, reason)
    VALUES (%s, %s, %s, %s)
    RETURNING id
"""

SELECT_PENDING_DISPATCHES = """
    SELECT id, job_key, requested_by, parameters, reason, created_at
    FROM dispatch_queue
    WHERE status = 'pending'
    ORDER BY created_at ASC
    LIMIT %s
    FOR UPDATE SKIP LOCKED
"""

UPDATE_DISPATCH_CLAIMED = """
    UPDATE dispatch_queue SET status = 'claimed', claimed_at = NOW()
    WHERE id = %s AND status = 'pending'
"""

UPDATE_DISPATCH_COMPLETED = """
    UPDATE dispatch_queue SET status = %s, completed_at = NOW()
    WHERE id = %s
"""


# -- Adapter State -----------------------------------------------------------

UPSERT_ADAPTER_STATE = """
    INSERT INTO adapter_state (adapter_name, last_cursor, last_success_at, error_count, metadata, updated_at)
    VALUES (%s, %s, %s, %s, %s, NOW())
    ON CONFLICT (adapter_name) DO UPDATE SET
        last_cursor = EXCLUDED.last_cursor,
        last_success_at = EXCLUDED.last_success_at,
        error_count = EXCLUDED.error_count,
        metadata = EXCLUDED.metadata,
        updated_at = NOW()
"""

SELECT_ADAPTER_STATE = """
    SELECT adapter_name, last_cursor, last_success_at, last_error_at, error_count, metadata
    FROM adapter_state
    WHERE adapter_name = %s
"""

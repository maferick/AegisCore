<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §18 — auto-refresh AI hypothesis summaries.
 *
 * Tracks per-hypothesis AI summary state so the auto-refresh command
 * can decide eligibility (skip when fresh, skip when evidence
 * unchanged, retry on failure) without consulting intel_audit_log.
 *
 * Columns are independent from the existing ai_model + ai_prompt_hash
 * fields:
 *   - ai_model / ai_prompt_hash were the v1 stamp; kept for the
 *     CounterIntelCommand badge that already reads them
 *   - ai_summary_* are the v2 lifecycle fields used by
 *     counter-intel:ai-refresh-stale
 *
 * Both sets are written on every successful synthesis. On graceful
 * failure (provider down, validation reject), only the failure
 * tracking columns advance — generated_at + evidence_hash stay where
 * they were so the prior summary remains the visible truth.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE counter_intel_hypotheses
              ADD COLUMN ai_summary_generated_at DATETIME NULL AFTER ai_prompt_hash,
              ADD COLUMN ai_summary_freshness_state ENUM('fresh','aging','stale','expired') NULL AFTER ai_summary_generated_at,
              ADD COLUMN ai_summary_evidence_hash CHAR(64) NULL AFTER ai_summary_freshness_state,
              ADD COLUMN ai_summary_model VARCHAR(120) NULL AFTER ai_summary_evidence_hash,
              ADD COLUMN ai_summary_tier VARCHAR(20) NULL AFTER ai_summary_model,
              ADD COLUMN ai_summary_latency_ms INT UNSIGNED NULL AFTER ai_summary_tier,
              ADD COLUMN ai_summary_attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER ai_summary_latency_ms,
              ADD COLUMN ai_summary_last_attempt_at DATETIME NULL AFTER ai_summary_attempt_count,
              ADD COLUMN ai_summary_failure_reason VARCHAR(120) NULL AFTER ai_summary_last_attempt_at,
              ADD INDEX idx_ai_summary_freshness (viewer_bloc_id, ai_summary_freshness_state),
              ADD INDEX idx_ai_summary_eligibility (viewer_bloc_id, ai_summary_generated_at)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE counter_intel_hypotheses
              DROP INDEX idx_ai_summary_freshness,
              DROP INDEX idx_ai_summary_eligibility,
              DROP COLUMN ai_summary_generated_at,
              DROP COLUMN ai_summary_freshness_state,
              DROP COLUMN ai_summary_evidence_hash,
              DROP COLUMN ai_summary_model,
              DROP COLUMN ai_summary_tier,
              DROP COLUMN ai_summary_latency_ms,
              DROP COLUMN ai_summary_attempt_count,
              DROP COLUMN ai_summary_last_attempt_at,
              DROP COLUMN ai_summary_failure_reason
        SQL);
    }
};

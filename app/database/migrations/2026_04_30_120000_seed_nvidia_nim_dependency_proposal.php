<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V1 freeze §"Forbidden" #8 — adopting a new third-party API requires
 * a calibration_proposals row of kind=`dependency_addition` with
 * dual sign-off. Single-operator reality (ADR 0012) collapses
 * dual sign-off into single self-approval; this migration logs the
 * paper trail.
 *
 * Dependency: NVIDIA NIM (chat completions) for safe-AI surfaces
 * authorised under ADR 0013 (hypothesis synthesis, summarization,
 * narrative generation). Used out-of-band only — never inside a
 * Laravel queue job, never on a request path with p95 < 2s budget.
 *
 * Idempotent: re-running the migration is a no-op once the row exists.
 */
return new class extends Migration {
    public function up(): void
    {
        $exists = DB::table('calibration_proposals')
            ->where('surface', 'ai_runtime')
            ->where('field', 'nvidia_nim_dependency')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('calibration_proposals')->insert([
            'proposal_date' => '2026-04-30',
            'surface' => 'ai_runtime',
            'field' => 'nvidia_nim_dependency',
            'prior_value' => null,
            'proposed_value' => 'nvidia_nim:integrate.api.nvidia.com/v1',
            'evidence_json' => json_encode([
                'kind' => 'dependency_addition',
                'adr_basis' => ['0012', '0013'],
                'use_cases' => [
                    'counter_intel_hypothesis_synthesis',
                    'narrative_generation',
                    'evidence_prioritization',
                    'summarization',
                ],
                'plane_boundary_compliance' => 'out-of-band only — artisan + queue jobs explicitly excluded; no Livewire / Filament call paths',
                'reversibility' => 'NVIDIA_NIM_API_KEY env removal disables surface in ≤1 deploy; no schema lock-in',
                'failure_mode' => 'graceful degradation — surfaces fall back to non-AI hypothesis_summary; CI never blocked by NIM availability',
                'six_field_compliance' => 'AI prompts enforce ADR-0013 confidence/evidence/source/caveats/freshness/why-strengthened in JSON output schema',
                'audit' => 'every NIM-influenced row written via intel_audit_log with actor_kind=ai, surface=ai_hypothesis',
            ]),
            'status' => 'adopted',
            'reviewer_user_ids' => json_encode(['operator_self_signoff_under_adr_0012']),
            'baseline_ref' => null,
            'rationale' => 'Single-operator self-approval under ADR 0012. Safe-AI scope per ADR 0013; reversible; out-of-band only; six-field UI/UX rule enforced in service-layer JSON validation; audit log entries on every inference.',
            'decided_at' => now(),
            'superseded_by_id' => null,
            'created_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('calibration_proposals')
            ->where('surface', 'ai_runtime')
            ->where('field', 'nvidia_nim_dependency')
            ->delete();
    }
};

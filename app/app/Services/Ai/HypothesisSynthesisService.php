<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\IntelAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;

/**
 * Counter-Intel hypothesis synthesis (ADR 0013, hypothesis-shaped).
 *
 * Takes a fused counter_intel_hypotheses row, asks the configured
 * AI backend (NVIDIA NIM) to synthesise a richer narrative, and
 * persists the output:
 *   - row.hypothesis_summary updated (one-liner)
 *   - row.ai_model + ai_prompt_hash stamped
 *   - intel_audit_log row written (actor_kind='ai',
 *     surface='ai_hypothesis')
 *
 * Hard rules from ADR 0013:
 *   - output JSON includes confidence_band, evidence list with
 *     source refs, caveats, freshness, why-strengthened, and
 *     next investigation steps
 *   - never escalates the row's confidence band on its own — the
 *     caller still does the band promotion via the fusion compute
 *   - never attaches an action — the operator decides what to do
 *   - graceful failure: returns null when the AI is unavailable
 *     or the output fails validation; the caller treats that as
 *     "no enrichment this run", not as an error
 */
final class HypothesisSynthesisService
{
    /** Required keys in the AI's JSON response. */
    private const REQUIRED_FIELDS = [
        'title',
        'summary',
        'hypothesis_type',
        'confidence_band',
        'confidence_reasoning',
        'key_evidence',
        'caveats',
        'freshness',
        'why_strengthened',
        'next_investigation_steps',
    ];

    private const ALLOWED_BANDS = ['low', 'medium', 'high', 'confirmed'];

    /** Auto-refresh freshness windows (hours). */
    public const FRESH_HOURS = 24;
    public const AGING_HOURS = 72;
    public const STALE_HOURS = 168; // 7 days

    public function __construct(private readonly NvidiaNimClient $nim)
    {
    }

    /**
     * Synthesise a single hypothesis row by id. Returns the parsed
     * JSON output + metadata, or null on graceful failure.
     *
     * @return array{
     *   hypothesis_id: int,
     *   data: array<string, mixed>,
     *   meta: array<string, mixed>,
     * }|null
     */
    public function synthesize(int $hypothesisId, string $tier = NvidiaNimClient::TIER_FAST): ?array
    {
        $row = DB::table('counter_intel_hypotheses')->where('id', $hypothesisId)->first();
        if ($row === null) {
            return null;
        }
        return $this->synthesizeRow($row, $tier);
    }

    /**
     * Synthesise from a pre-fetched row. Used by batch commands so
     * the caller can iterate without extra round-trips.
     *
     * Tiers:
     *   - fast  (default): primary model + fallback safety net,
     *     bounded budget. Hypothesis-loop work.
     *   - heavy: heavy summary model, larger budget, no auto-fallback.
     *     For final-output / "what changed" / CI command surface.
     */
    public function synthesizeRow(stdClass $row, string $tier = NvidiaNimClient::TIER_FAST, bool $skipIfFresh = false): ?array
    {
        if (! $this->nim->isConfigured()) {
            $this->recordFailedAttempt((int) $row->id, $tier, 'nim_not_configured');
            return null;
        }

        $evidenceHash = self::evidenceHash($row);
        if ($skipIfFresh && $this->isFreshAndUnchanged($row, $evidenceHash)) {
            return null;
        }

        $messages = $this->buildMessages($row);
        $allowedTables = $this->extractAllowedSourceTables($row);

        $result = $tier === NvidiaNimClient::TIER_HEAVY
            ? $this->nim->chatTierJsonOrNull(NvidiaNimClient::TIER_HEAVY, $messages)
            : $this->nim->chatJsonOrNull($messages, [
                'max_tokens' => 1500,
                'temperature' => 0.2,
            ]);

        if ($result === null) {
            $this->recordFailedAttempt((int) $row->id, $tier, 'provider_failure');
            return null;
        }

        $validated = $this->validate($result['data']);
        if ($validated === null) {
            Log::warning('hypothesis_synthesis.validation_failed', [
                'hypothesis_id' => (int) $row->id,
                'model_used' => $result['meta']['model_used'] ?? null,
                'prompt_hash' => $result['meta']['prompt_hash'] ?? null,
                'missing_or_bad_fields' => $this->describeValidationFailure($result['data']),
            ]);
            $this->recordFailedAttempt((int) $row->id, $tier, 'validation_failed');
            return null;
        }

        // No-hallucinate guard: drop key_evidence rows that cite a
        // source_table the model did not actually see in the input.
        // ADR 0013 §"hide its reasoning" — every inference must be
        // explainable from the rows it cites; an invented source_table
        // is a black-box claim and gets stripped.
        $cleaned = $this->stripHallucinatedSources($validated, $allowedTables);
        if (! is_array($cleaned['data']['key_evidence'] ?? null)
            || $cleaned['data']['key_evidence'] === []) {
            Log::warning('hypothesis_synthesis.all_evidence_hallucinated', [
                'hypothesis_id' => (int) $row->id,
                'model_used' => $result['meta']['model_used'] ?? null,
                'allowed_tables' => $allowedTables,
                'dropped_count' => $cleaned['dropped_count'],
            ]);
            $this->recordFailedAttempt((int) $row->id, $tier, 'all_evidence_hallucinated');
            return null;
        }
        $validated = $cleaned['data'];
        $meta = $result['meta'];
        $meta['evidence_dropped_for_hallucinated_source'] = $cleaned['dropped_count'];
        $meta['tier'] = $tier;
        $meta['evidence_hash'] = $evidenceHash;

        $this->persist((int) $row->id, $row, $validated, $meta);

        return [
            'hypothesis_id' => (int) $row->id,
            'data' => $validated,
            'meta' => $meta,
        ];
    }

    /**
     * Set of source_table values that the prompt actually exposed. Used
     * by the no-hallucinate validator to scrub invented citations.
     *
     * @return array<int, string>
     */
    private function extractAllowedSourceTables(stdClass $row): array
    {
        $tables = ['counter_intel_hypotheses'];

        $signalRefs = self::safeJson((string) ($row->source_signal_refs_json ?? '')) ?? [];
        if (is_array($signalRefs)) {
            foreach ($signalRefs as $ref) {
                if (is_array($ref) && isset($ref['source_table']) && is_string($ref['source_table'])) {
                    $tables[] = $ref['source_table'];
                }
                if (is_array($ref) && isset($ref['table']) && is_string($ref['table'])) {
                    $tables[] = $ref['table'];
                }
            }
        }

        // Fusion compute writes evidence rows whose `kind` maps to known
        // upstream tables. List them explicitly so the validator
        // recognises them as legitimate citations.
        foreach ([
            'ci_character_features_rolling',
            'ci_character_anomalies_rolling',
            'ci_combat_anomalies',
            'ci_character_ground_truth',
            'character_corporation_history',
            'killmails',
            'killmail_attackers',
            'killmail_victims',
            'esi_entity_names',
            'coalition_entity_labels',
            'doctrine_match_results',
        ] as $defaultAllowed) {
            $tables[] = $defaultAllowed;
        }

        return array_values(array_unique($tables));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<int, string>    $allowedTables
     * @return array{data: array<string,mixed>, dropped_count: int}
     */
    private function stripHallucinatedSources(array $validated, array $allowedTables): array
    {
        $allowedSet = array_flip($allowedTables);
        $dropped = 0;
        $kept = [];
        foreach ((array) ($validated['key_evidence'] ?? []) as $ev) {
            if (! is_array($ev)) {
                $dropped++;
                continue;
            }
            $tbl = (string) ($ev['source_table'] ?? '');
            if ($tbl === '' || ! isset($allowedSet[$tbl])) {
                $dropped++;
                continue;
            }
            // Server-side source_link generation — never trust the AI
            // to invent URLs. Whatever the model emitted is overwritten
            // with the canonical portal route for the cited table+ids,
            // or dropped entirely when no canonical mapping exists.
            $ev['source_link'] = $this->canonicalSourceLink(
                $tbl,
                is_array($ev['source_ids'] ?? null) ? $ev['source_ids'] : [],
            );
            $kept[] = $ev;
        }
        $validated['key_evidence'] = $kept;
        return ['data' => $validated, 'dropped_count' => $dropped];
    }

    /**
     * Map (source_table, source_ids) → canonical portal URL. Returns
     * null when no mapping is known for the table — caller renders the
     * citation without a clickable link rather than risk a 404.
     *
     * @param  array<int, mixed>  $sourceIds
     */
    private function canonicalSourceLink(string $sourceTable, array $sourceIds): ?string
    {
        $firstId = null;
        foreach ($sourceIds as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $firstId = (int) $id;
                break;
            }
        }
        if ($firstId === null) {
            return null;
        }

        return match ($sourceTable) {
            'ci_character_features_rolling',
            'ci_character_anomalies_rolling',
            'ci_combat_anomalies',
            'ci_character_ground_truth',
            'character_corporation_history',
            'esi_entity_names'
                => '/portal/characters/lookup?cid='.$firstId,
            'killmails', 'killmail_attackers', 'killmail_victims'
                => '/portal/killmails/'.$firstId,
            'counter_intel_hypotheses'
                => '/portal/counter-intel/command#hypothesis-'.$firstId,
            default => null,
        };
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(stdClass $row): array
    {
        $evidence = self::safeJson((string) ($row->evidence_summary_json ?? '')) ?? [];
        $signalRefs = self::safeJson((string) ($row->source_signal_refs_json ?? '')) ?? [];
        $caveats = self::safeJson((string) ($row->caveats_json ?? '')) ?? [];
        $why = self::safeJson((string) ($row->why_strengthened_json ?? '')) ?? [];

        $primaryName = DB::table('esi_entity_names')
            ->where('entity_id', (int) $row->primary_character_id)
            ->where('category', 'character')
            ->value('name');

        $context = [
            'hypothesis_id' => (int) $row->id,
            'viewer_bloc_id' => (int) $row->viewer_bloc_id,
            'hypothesis_type' => (string) $row->hypothesis_type,
            'primary_character' => [
                'id' => (int) $row->primary_character_id,
                'name' => $primaryName,
            ],
            'related_character_ids' => self::safeJson((string) ($row->related_character_ids_json ?? '')) ?? [],
            'current_confidence_band' => (string) $row->confidence,
            'severity' => (string) $row->severity,
            'suspicion_score' => (float) $row->suspicion_score,
            'evidence_count' => (int) $row->evidence_count,
            'corroboration_count' => (int) $row->corroboration_count,
            'first_seen_at' => (string) $row->first_seen_at,
            'last_strengthened_at' => (string) $row->last_strengthened_at,
            'last_recomputed_at' => (string) $row->last_recomputed_at,
            'freshness_state' => (string) $row->freshness_state,
            'top_fused_signals' => $evidence,
            'source_signal_refs' => $signalRefs,
            'existing_caveats' => $caveats,
            'why_strengthened_delta' => $why,
            'existing_summary' => (string) $row->hypothesis_summary,
        ];

        $system = <<<'SYS'
You are an analyst aide for a counter-intelligence platform.
You synthesise hypothesis narratives. You DO NOT make verdicts and
you NEVER claim a person "is a spy" — operational suspicion is a
band, not a verdict.

Strict rules (ADR 0013):
1. Output a JSON object only. No prose outside JSON. No markdown fences.
2. Required top-level keys: title, summary, hypothesis_type,
   confidence_band, confidence_reasoning, key_evidence (array),
   caveats (array), freshness (object), why_strengthened (string),
   next_investigation_steps (array).
3. confidence_band MUST be one of: low | medium | high | confirmed.
   Use the row's current band as the floor — you may keep or lower
   it (with reasoning), but DO NOT raise it.
4. Each item in key_evidence MUST be an object with keys: claim
   (short string), source_table (string), source_ids (array of
   integers/strings). Cite the actual table + ids you saw in the
   input — never invent. DO NOT emit source_link — the platform
   generates canonical URLs server-side; any URL you write is
   discarded.
5. caveats MUST list what could weaken the hypothesis (sample size,
   freshness, contamination, alternative explanations).
6. freshness MUST be an object with: oldest_signal_at, newest_signal_at,
   stale (bool). Use ISO 8601 datetimes.
7. why_strengthened MUST describe what is new since the prior render
   (or the literal string "initial" if first synthesis).
8. next_investigation_steps MUST be query-shaped suggestions for the
   operator; never an action against the operator.
9. Use language: "possible", "operational suspicion", "requires
   corroboration". Forbidden: "spy", "alt of", "guilty",
   "confirmed bad actor".
10. Keep title <= 90 chars. Keep summary <= 600 chars.
SYS;

        $user = "Counter-Intel hypothesis fusion row (UTF-8 JSON):\n\n"
            .json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ."\n\nProduce the synthesis JSON now.";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function validate(array $data): ?array
    {
        foreach (self::REQUIRED_FIELDS as $f) {
            if (! array_key_exists($f, $data)) {
                return null;
            }
        }
        if (! is_string($data['title']) || trim($data['title']) === '') {
            return null;
        }
        if (! is_string($data['summary']) || trim($data['summary']) === '') {
            return null;
        }
        if (! is_string($data['confidence_band'])
            || ! in_array(strtolower($data['confidence_band']), self::ALLOWED_BANDS, true)) {
            return null;
        }
        if (! is_array($data['key_evidence']) || $data['key_evidence'] === []) {
            return null;
        }
        if (! is_array($data['caveats'])) {
            return null;
        }
        if (! is_array($data['freshness'])) {
            return null;
        }
        if (! array_key_exists('stale', $data['freshness'])) {
            return null;
        }
        if (! is_string($data['why_strengthened'])) {
            return null;
        }
        if (! is_array($data['next_investigation_steps'])) {
            return null;
        }

        // Trim title / summary defensively in case the model overruns.
        $data['title'] = mb_substr(trim((string) $data['title']), 0, 200);
        $data['summary'] = mb_substr(trim((string) $data['summary']), 0, 1500);
        $data['confidence_band'] = strtolower((string) $data['confidence_band']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function describeValidationFailure(array $data): array
    {
        $issues = [];
        foreach (self::REQUIRED_FIELDS as $f) {
            if (! array_key_exists($f, $data)) {
                $issues[] = 'missing:'.$f;
            }
        }
        if (isset($data['confidence_band'])
            && ! in_array(strtolower((string) $data['confidence_band']), self::ALLOWED_BANDS, true)) {
            $issues[] = 'bad_band:'.((string) $data['confidence_band']);
        }
        return $issues;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $meta
     */
    private function persist(int $hypothesisId, stdClass $priorRow, array $validated, array $meta): void
    {
        $newSummary = mb_substr((string) $validated['title'], 0, 500);
        $now = now();
        $modelUsed = mb_substr((string) ($meta['model_used'] ?? ''), 0, 120);
        $tier = mb_substr((string) ($meta['tier'] ?? NvidiaNimClient::TIER_FAST), 0, 20);

        $prior = [
            'hypothesis_summary' => (string) $priorRow->hypothesis_summary,
            'ai_model' => $priorRow->ai_model,
            'ai_prompt_hash' => $priorRow->ai_prompt_hash,
        ];

        $newState = [
            'hypothesis_summary' => $newSummary,
            'ai_model' => $modelUsed,
            'ai_prompt_hash' => mb_substr((string) ($meta['prompt_hash'] ?? ''), 0, 64),
            'ai_summary_generated_at' => $now,
            'ai_summary_freshness_state' => 'fresh',
            'ai_summary_evidence_hash' => mb_substr((string) ($meta['evidence_hash'] ?? ''), 0, 64),
            'ai_summary_model' => $modelUsed,
            'ai_summary_tier' => $tier,
            'ai_summary_latency_ms' => (int) ($meta['latency_ms'] ?? 0),
            'ai_summary_attempt_count' => (int) ($priorRow->ai_summary_attempt_count ?? 0) + 1,
            'ai_summary_last_attempt_at' => $now,
            'ai_summary_failure_reason' => null,
        ];

        DB::table('counter_intel_hypotheses')
            ->where('id', $hypothesisId)
            ->update($newState + ['updated_at' => $now]);

        IntelAuditLog::recordAi(
            IntelAuditLog::SURFACE_AI_HYPOTHESIS,
            $hypothesisId,
            'synthesize',
            $prior,
            $newState + ['ai_output' => $validated],
            [
                'tier' => (string) ($meta['tier'] ?? 'fast'),
                'model_requested' => $meta['model_requested'] ?? null,
                'model_used' => $meta['model_used'] ?? null,
                'fell_back' => (bool) ($meta['fell_back'] ?? false),
                'latency_ms' => (int) ($meta['latency_ms'] ?? 0),
                'attempts' => (int) ($meta['attempts'] ?? 0),
                'usage' => $meta['usage'] ?? null,
                'evidence_dropped_for_hallucinated_source' => (int) ($meta['evidence_dropped_for_hallucinated_source'] ?? 0),
                'adr_basis' => ['0012', '0013'],
            ],
        );
    }

    private static function safeJson(string $raw): mixed
    {
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Stable hash of the fusion evidence payload — used by the auto-
     * refresh command to detect whether the row's signals changed
     * since the last AI synthesis. Unchanged hash + fresh summary =
     * skip.
     */
    public static function evidenceHash(stdClass $row): string
    {
        $payload = (string) ($row->evidence_summary_json ?? '')
            .'|'.(string) ($row->source_signal_refs_json ?? '')
            .'|'.(string) ($row->confidence ?? '')
            .'|'.(string) ($row->severity ?? '')
            .'|'.(string) ($row->suspicion_score ?? '');
        return hash('sha256', $payload);
    }

    /**
     * Map an `ai_summary_generated_at` timestamp into the four-band
     * freshness state used by the auto-refresh eligibility logic.
     */
    public static function freshnessState(?string $generatedAt): ?string
    {
        if ($generatedAt === null || $generatedAt === '') {
            return null;
        }
        $hours = now()->diffInHours($generatedAt, true);
        if ($hours < self::FRESH_HOURS) return 'fresh';
        if ($hours < self::AGING_HOURS) return 'aging';
        if ($hours < self::STALE_HOURS) return 'stale';
        return 'expired';
    }

    /**
     * Skip predicate for the auto-refresh path. Skip when the existing
     * summary is < 24h old AND derived from the same evidence hash.
     * Manual operator-triggered refreshes ignore this gate.
     */
    private function isFreshAndUnchanged(stdClass $row, string $newHash): bool
    {
        $existing = $row->ai_summary_evidence_hash ?? null;
        $generatedAt = $row->ai_summary_generated_at ?? null;
        if ($existing === null || $generatedAt === null) {
            return false;
        }
        if ($existing !== $newHash) {
            return false;
        }
        return self::freshnessState((string) $generatedAt) === 'fresh';
    }

    /**
     * Recovery-side bookkeeping when a synthesis attempt fails. Bumps
     * the attempt counter, stamps the reason + last_attempt_at, leaves
     * the prior summary visible, and writes a `synthesize_failed`
     * audit row so the failure is queryable from intel_audit_log.
     */
    private function recordFailedAttempt(int $hypothesisId, string $tier, string $reason): void
    {
        $now = now();
        DB::table('counter_intel_hypotheses')
            ->where('id', $hypothesisId)
            ->update([
                'ai_summary_attempt_count' => DB::raw('ai_summary_attempt_count + 1'),
                'ai_summary_last_attempt_at' => $now,
                'ai_summary_failure_reason' => mb_substr($reason, 0, 120),
                'ai_summary_tier' => mb_substr($tier, 0, 20),
                'updated_at' => $now,
            ]);

        IntelAuditLog::recordAi(
            IntelAuditLog::SURFACE_AI_HYPOTHESIS,
            $hypothesisId,
            'synthesize_failed',
            null,
            ['failure_reason' => $reason, 'tier' => $tier],
            ['adr_basis' => ['0012', '0013']],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Domains\CounterIntel\Jobs\RefineHypothesisJob;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/counter-intel/command — Counter-Intel Command Surface.
 *
 * The single page where the operator sees the strongest active
 * suspicious hypotheses ranked + explained. Reads counter_intel_
 * hypotheses (written by the Python phase18-hypothesis-fusion
 * pipeline) and renders one card per row with the six binding
 * ADR-0013 fields (confidence, evidence, source refs, caveats,
 * freshness, why-strengthened) plus the strongest corroborating
 * signals.
 *
 * Filter dropdown: confidence band + status.
 * Sort: suspicion_score DESC.
 *
 * Read-only — no state mutation here. Operator status changes
 * live on each character's lookup card / watchlist entry.
 */
class CounterIntelCommand extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationLabel = 'CI Command (deep-dive)';

    protected static string|UnitEnum|null $navigationGroup = 'Daily ops';

    // Sort 5 — sits below Counter-Intel Overview (sort 4) so the
    // operator's first click in Daily-ops lands on the compact
    // Overview, not the long expandable Command stream.
    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Counter-Intel Command';

    protected static ?string $slug = 'counter-intel/command';

    protected string $view = 'filament.portal.pages.counter-intel-command';

    public ?string $minBand = 'high';

    public function mount(): void
    {
        // Default to 'high' so the operator sees the strongest queue
        // first. Medium / low surface only on explicit drill-down via
        // the band selector — keeps the page digestible.
        $b = (string) (request()->query('min_band') ?? 'high');
        if (! in_array($b, ['low', 'medium', 'high', 'confirmed'], true)) {
            $b = 'high';
        }
        $this->minBand = $b;
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) {
            return ['no_bloc' => true];
        }

        $bands = match ($this->minBand) {
            'low'      => ['low', 'medium', 'high', 'confirmed'],
            'medium'   => ['medium', 'high', 'confirmed'],
            'high'     => ['high', 'confirmed'],
            'confirmed' => ['confirmed'],
            default    => ['medium', 'high', 'confirmed'],
        };

        // Loop 21 — cap at 25 cards by default. Operator-readable
        // page wants the top of the queue, not the full backlog.
        $rows = DB::table('counter_intel_hypotheses')
            ->where('viewer_bloc_id', $blocId)
            ->whereIn('confidence', $bands)
            ->where('status', '<>', 'archived')
            ->orderByRaw("FIELD(confidence,'confirmed','high','medium','low')")
            ->orderByRaw("FIELD(severity,'critical','elevated','watch','info')")
            ->orderByDesc('suspicion_score')
            ->orderByDesc('last_strengthened_at')
            ->limit(25)
            ->get();

        $hypothesisIds = $rows->pluck('id')->map(fn ($v) => (int) $v)->all();
        $aiStatusByRefId = $hypothesisIds === [] ? [] : $this->loadAiStatusForHypotheses($hypothesisIds);

        $cards = [];
        foreach ($rows as $r) {
            $signals = $this->safeJson($r->evidence_summary_json) ?? [];
            $refs    = $this->safeJson($r->source_signal_refs_json) ?? [];
            $caveats = $this->safeJson($r->caveats_json) ?? [];
            $why     = $this->safeJson($r->why_strengthened_json) ?? [];
            $name = DB::table('esi_entity_names')
                ->where('entity_id', $r->primary_character_id)
                ->where('category', 'character')
                ->value('name');
            $aiStatus = $aiStatusByRefId[(int) $r->id] ?? null;
            $cards[] = [
                'id' => (int) $r->id,
                'character_id' => (int) $r->primary_character_id,
                'character_name' => $name ?? ('#'.$r->primary_character_id),
                'confidence' => (string) $r->confidence,
                'severity'   => (string) $r->severity,
                'score'      => (float) $r->suspicion_score,
                'corroboration' => (int) $r->corroboration_count,
                'first_seen_at'        => (string) $r->first_seen_at,
                'last_strengthened_at' => (string) $r->last_strengthened_at,
                'last_recomputed_at'   => (string) $r->last_recomputed_at,
                'freshness_state'      => (string) $r->freshness_state,
                'status' => (string) $r->status,
                'summary' => (string) $r->hypothesis_summary,
                'signals' => is_array($signals) ? $signals : [],
                'source_refs' => is_array($refs) ? $refs : [],
                'caveats' => is_array($caveats) ? $caveats : [],
                'why_strengthened' => is_array($why) ? $why : [],
                'ai_model' => $r->ai_model,
                'ai_status' => $aiStatus,
            ];
        }

        // Distribution counters for the page-level verdict.
        $totals = DB::table('counter_intel_hypotheses')
            ->where('viewer_bloc_id', $blocId)
            ->where('status', '<>', 'archived')
            ->groupBy('confidence')
            ->selectRaw('confidence, COUNT(*) AS n')
            ->pluck('n', 'confidence')
            ->all();

        // Loop 18 — alt-cluster hint via name-prefix grouping. When
        // 3+ active hypotheses share the first word of the pilot
        // name (e.g. "Bakkanta one / Bakkanta to / Bakkanta Aviai
        // Odunen"), tag them with a cluster_hint so the renderer
        // can group them visually. NOT a verdict — purely a hint
        // for the operator to consider as alt-pattern.
        $prefixGroups = [];
        foreach ($cards as $i => $c) {
            $first = mb_strtolower(strtok((string) $c['character_name'], ' '));
            if (mb_strlen($first) < 4 || ctype_digit($first)) continue; // skip "#1234" / 1-3 char prefixes
            $prefixGroups[$first][] = $i;
        }
        foreach ($prefixGroups as $prefix => $indices) {
            if (count($indices) < 3) continue;
            foreach ($indices as $i) {
                $cards[$i]['cluster_hint'] = [
                    'prefix' => $prefix,
                    'sibling_count' => count($indices) - 1,
                ];
            }
        }

        $verdict = $this->buildVerdict($totals, count($cards));

        return [
            'no_bloc' => false,
            'verdict' => $verdict,
            'min_band' => $this->minBand,
            'available_bands' => ['low', 'medium', 'high', 'confirmed'],
            'totals' => $totals,
            'cards' => $cards,
            'last_run' => DB::table('counter_intel_hypotheses')
                ->where('viewer_bloc_id', $blocId)
                ->max('last_recomputed_at'),
        ];
    }

    /** @return array{severity:string, headline:string, details:array<int, string>} */
    private function buildVerdict(array $totals, int $shownCount): array
    {
        $high      = (int) ($totals['high']      ?? 0);
        $medium    = (int) ($totals['medium']    ?? 0);
        $confirmed = (int) ($totals['confirmed'] ?? 0);
        $details = [];
        if ($confirmed > 0) $details[] = "{$confirmed} operator-confirmed";
        if ($high > 0)      $details[] = "{$high} high-confidence";
        if ($medium > 0)    $details[] = "{$medium} medium-confidence";
        $details[] = "{$shownCount} shown · review the top of the queue first";

        $severity = 'info';
        $headline = 'No active high-confidence hypotheses';
        if ($confirmed > 0 || $high > 0) {
            $severity = 'critical';
            $headline = 'High-confidence hypotheses warrant analyst review';
        } elseif ($medium > 0) {
            $severity = 'warning';
            $headline = 'Medium-confidence hypotheses present';
        }
        return ['severity' => $severity, 'headline' => $headline, 'details' => $details];
    }

    private function safeJson(?string $raw): mixed
    {
        if ($raw === null || $raw === '') return null;
        return json_decode($raw, true);
    }

    /**
     * Latest ai_hypothesis audit row per hypothesis id, indexed by
     * surface_ref_id. One subquery picks MAX(id) per ref so the
     * payload reflects the most recent synthesis (fast or heavy).
     *
     * @param  array<int, int>  $hypothesisIds
     * @return array<int, array{tier:string, model_used:?string, generated_at:?string, latency_ms:int, evidence_count:int, hallucination_drops:int, fell_back:bool}>
     */
    private function loadAiStatusForHypotheses(array $hypothesisIds): array
    {
        $latestIds = DB::table('intel_audit_log')
            ->where('surface', 'ai_hypothesis')
            ->whereIn('surface_ref_id', $hypothesisIds)
            ->selectRaw('MAX(id) AS max_id')
            ->groupBy('surface_ref_id')
            ->pluck('max_id')
            ->all();

        if ($latestIds === []) {
            return [];
        }

        $rows = DB::table('intel_audit_log')
            ->whereIn('id', $latestIds)
            ->get(['id', 'surface_ref_id', 'metadata_json', 'new_state_json', 'created_at']);

        $out = [];
        foreach ($rows as $row) {
            $meta = json_decode((string) ($row->metadata_json ?? ''), true);
            if (! is_array($meta)) {
                $meta = [];
            }
            $newState = json_decode((string) ($row->new_state_json ?? ''), true);
            $aiOutput = is_array($newState) ? ($newState['ai_output'] ?? []) : [];
            $evidence = is_array($aiOutput) ? ($aiOutput['key_evidence'] ?? []) : [];

            $out[(int) $row->surface_ref_id] = [
                'tier' => (string) ($meta['tier'] ?? 'fast'),
                'model_used' => isset($meta['model_used']) ? (string) $meta['model_used'] : null,
                'generated_at' => (string) ($row->created_at ?? ''),
                'latency_ms' => (int) ($meta['latency_ms'] ?? 0),
                'evidence_count' => is_array($evidence) ? count($evidence) : 0,
                'hallucination_drops' => (int) ($meta['evidence_dropped_for_hallucinated_source'] ?? 0),
                'fell_back' => (bool) ($meta['fell_back'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * Operator-triggered "Refine with heavy model" action. Dispatches
     * a queued RefineHypothesisJob (timeout=240, ShouldBeUnique on
     * hypothesis id). Operator gets a toast + refreshes the surface
     * once the job lands. Heavy tier never auto-batches: one row per
     * click.
     */
    public function refineHeavy(int $hypothesisId): void
    {
        $row = DB::table('counter_intel_hypotheses')
            ->where('id', $hypothesisId)
            ->first(['id', 'viewer_bloc_id']);

        if ($row === null) {
            Notification::make()
                ->title('Hypothesis not found')
                ->danger()
                ->send();
            return;
        }

        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null || (int) $row->viewer_bloc_id !== $blocId) {
            Notification::make()
                ->title('Out-of-bloc hypothesis — refresh and try again')
                ->danger()
                ->send();
            return;
        }

        RefineHypothesisJob::dispatch($hypothesisId);

        Notification::make()
            ->title('Heavy refinement queued')
            ->body('mistral-large-3 takes ~30–180s. Refresh in a minute to see the updated summary.')
            ->success()
            ->send();
    }

    private function resolveViewerBlocId(): ?int
    {
        $override = request()->query('bloc_id');
        if ($override !== null && ctype_digit((string) $override)) {
            return (int) $override;
        }
        $user = Auth::user();
        if ($user === null) return null;
        $char = $user->characters()->first();
        if ($char === null || ! $char->alliance_id) return null;
        $blocId = DB::table('coalition_entity_labels')
            ->where('entity_type', 'alliance')
            ->where('entity_id', $char->alliance_id)
            ->where('is_active', 1)
            ->value('bloc_id');
        return $blocId ? (int) $blocId : null;
    }
}

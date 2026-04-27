<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
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

    protected static ?string $navigationLabel = 'CI Command';

    protected static string|UnitEnum|null $navigationGroup = 'Daily ops';

    protected static ?int $navigationSort = 3;

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

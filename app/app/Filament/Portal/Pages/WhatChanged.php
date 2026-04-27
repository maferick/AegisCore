<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/intelligence/what-changed — §17.1 first safe-AI surface.
 *
 * Reads operational_change_summaries (written by the Python
 * phase17-what-changed pipeline). Cards render the six binding
 * fields per ADR 0013:
 *
 *   confidence band, evidence list, source references, caveats,
 *   freshness state, why-strengthened.
 *
 * Read-only — operator clicks "view evidence" to expand source
 * row references; no AI mutation of analyst state. This page
 * does not trigger regeneration; the cron-driven Python pipeline
 * is the only writer.
 */
class WhatChanged extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'What changed';

    protected static string|UnitEnum|null $navigationGroup = 'Daily ops';

    protected static ?int $navigationSort = 14;

    protected static ?string $title = 'What changed';

    protected static ?string $slug = 'intelligence/what-changed';

    protected string $view = 'filament.portal.pages.what-changed';

    public ?string $window = '24h';

    public function mount(): void
    {
        $window = (string) (request()->query('window') ?? '24h');
        if (! in_array($window, ['1h', '6h', '24h', '7d'], true)) {
            $window = '24h';
        }
        $this->window = $window;
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) {
            return ['no_bloc' => true];
        }
        $window = $this->window ?? '24h';

        $rows = DB::table('operational_change_summaries')
            ->where('viewer_bloc_id', $blocId)
            ->where('window_type', $window)
            ->orderByRaw("FIELD(severity,'critical','elevated','warning','info')")
            ->orderByRaw("FIELD(confidence,'confirmed','high','medium','low')")
            ->orderByDesc('generated_at')
            ->get();

        $cards = [];
        foreach ($rows as $r) {
            $evidence = $this->safeJson($r->evidence_json);
            $sourceRefs = $this->safeJson($r->source_refs_json);
            $caveats = $this->safeJson($r->caveats_json);
            $why = $this->safeJson($r->why_strengthened_json);
            $cards[] = [
                'id' => (int) $r->id,
                'summary_type' => (string) $r->summary_type,
                'severity' => (string) $r->severity,
                'confidence' => (string) $r->confidence,
                'title' => (string) $r->title,
                'summary' => (string) $r->summary,
                'evidence' => $evidence,
                'source_refs' => is_array($sourceRefs) ? $sourceRefs : [],
                'caveats' => is_array($caveats) ? $caveats : [],
                'why_strengthened' => is_array($why) ? $why : [],
                'freshness_state' => (string) $r->freshness_state,
                'generated_at' => (string) $r->generated_at,
                'current_window_start' => (string) $r->current_window_start,
                'current_window_end' => (string) $r->current_window_end,
                'comparison_window_start' => (string) $r->comparison_window_start,
                'comparison_window_end' => (string) $r->comparison_window_end,
                'ai_model' => $r->ai_model,
            ];
        }

        // Newest run timestamp surfaces a "fresh enough?" signal at
        // the page level — operator can tell at a glance if the cron
        // is still firing.
        $latestGen = DB::table('operational_change_summaries')
            ->where('viewer_bloc_id', $blocId)
            ->where('window_type', $window)
            ->max('generated_at');

        return [
            'no_bloc' => false,
            'window' => $window,
            'cards' => $cards,
            'latest_generated_at' => $latestGen,
            'available_windows' => ['1h', '6h', '24h', '7d'],
        ];
    }

    private function safeJson(?string $raw): mixed
    {
        if ($raw === null || $raw === '') return null;
        $d = json_decode($raw, true);
        return $d;
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

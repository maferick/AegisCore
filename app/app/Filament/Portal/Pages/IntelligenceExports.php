<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * /portal/intelligence/exports — generate shareable intelligence
 * artifacts (markdown / JSON) backed by intel_export_artifacts.
 *
 * Generate, list recent, view by token. Each artifact carries a
 * 40-char share_token; the public route /portal/intel/share/{token}
 * resolves anonymously (within the bloc).
 */
class IntelligenceExports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Intel exports';

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?int $navigationSort = 12;

    protected static ?string $title = 'Intel exports';

    protected static ?string $slug = 'intelligence/exports';

    protected string $view = 'filament.portal.pages.intelligence-exports';

    public string $kind = 'operational_report';
    public string $format = 'markdown';
    public int $days = 7;

    public function mount(): void
    {
        $this->kind = (string) request()->query('kind', 'operational_report');
        if (! in_array($this->kind, [
            'operational_report', 'strategic_summary',
            'corridor_map', 'incident_timeline',
            'doctrine_evolution_report',
        ], true)) {
            $this->kind = 'operational_report';
        }
        $this->format = (string) request()->query('format', 'markdown');
        if (! in_array($this->format, ['markdown', 'json'], true)) {
            $this->format = 'markdown';
        }
        $this->days = max(1, min(90, (int) request()->query('days', 7)));
    }

    public function generate(): void
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) return;

        $payload = $this->buildExport($blocId, $this->kind, $this->days);

        $token = Str::random(40);
        $title = ucwords(str_replace('_', ' ', $this->kind))
            . " · last {$this->days} days · " . now()->format('Y-m-d');

        $bodyMd = null;
        $bodyJson = null;
        if ($this->format === 'markdown') {
            $bodyMd = $this->renderMarkdown($payload, $title);
            $bodyJson = json_encode($payload, JSON_PRETTY_PRINT);
        } else {
            $bodyJson = json_encode($payload, JSON_PRETTY_PRINT);
        }

        DB::table('intel_export_artifacts')->insert([
            'viewer_bloc_id' => $blocId,
            'artifact_kind' => $this->kind,
            'format' => $this->format,
            'share_token' => $token,
            'title' => mb_substr($title, 0, 220),
            'params_json' => json_encode([
                'days' => $this->days,
                'generated_at' => now()->toIso8601String(),
            ]),
            'body_md' => $bodyMd,
            'body_json' => $bodyJson,
            'created_by_user_id' => Auth::id(),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) {
            return ['no_bloc' => true];
        }

        $recent = DB::table('intel_export_artifacts')
            ->where('viewer_bloc_id', $blocId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return [
            'no_bloc' => false,
            'kind' => $this->kind,
            'format' => $this->format,
            'days' => $this->days,
            'recent' => $recent,
        ];
    }

    private function buildExport(int $blocId, string $kind, int $days): array
    {
        $cutoff = now()->subDays($days);

        switch ($kind) {
            case 'operational_report':
                return [
                    'kind' => 'operational_report',
                    'window_days' => $days,
                    'severity_counts' => DB::table('operational_incidents')
                        ->where('viewer_bloc_id', $blocId)
                        ->where('start_at', '>=', $cutoff)
                        ->groupBy('severity')
                        ->pluck(DB::raw('COUNT(*)'), 'severity')
                        ->all(),
                    'top_incidents' => DB::table('operational_incidents')
                        ->where('viewer_bloc_id', $blocId)
                        ->where('start_at', '>=', $cutoff)
                        ->whereIn('severity', ['strategic', 'escalation', 'coalition_level'])
                        ->orderByDesc('start_at')
                        ->limit(20)
                        ->get(),
                    'open_alerts' => DB::table('strategic_alerts')
                        ->where('viewer_bloc_id', $blocId)
                        ->whereNull('dismissed_at')
                        ->where('detected_at', '>=', $cutoff)
                        ->orderByDesc('detected_at')
                        ->limit(20)
                        ->get(),
                ];

            case 'strategic_summary':
                $latestEnd = DB::table('alliance_operational_profiles')
                    ->where('viewer_bloc_id', $blocId)
                    ->max('window_end');
                return [
                    'kind' => 'strategic_summary',
                    'profile_window_end' => $latestEnd,
                    'coalitions' => DB::table('coalition_behavior_comparisons')
                        ->where('viewer_bloc_id', $blocId)
                        ->where('window_end', $latestEnd)
                        ->get(),
                    'top_alliances' => DB::table('alliance_operational_profiles')
                        ->where('viewer_bloc_id', $blocId)
                        ->where('window_end', $latestEnd)
                        ->orderByDesc('incident_count')
                        ->limit(15)
                        ->get(),
                    'doctrine_events' => DB::table('doctrine_evolution_events')
                        ->where('viewer_bloc_id', $blocId)
                        ->where('window_end', '>=', now()->subDays($days)->toDateString())
                        ->orderByDesc('magnitude')
                        ->limit(15)
                        ->get(),
                ];

            case 'corridor_map':
                return [
                    'kind' => 'corridor_map',
                    'window_days' => $days,
                    'corridors' => DB::table('operational_corridors')
                        ->where('viewer_bloc_id', $blocId)
                        ->where('last_seen_at', '>=', $cutoff)
                        ->orderByDesc('transition_count')
                        ->limit(80)
                        ->get(),
                ];

            case 'incident_timeline':
                return [
                    'kind' => 'incident_timeline',
                    'window_days' => $days,
                    'incidents' => DB::table('operational_incidents')
                        ->where('viewer_bloc_id', $blocId)
                        ->where('start_at', '>=', $cutoff)
                        ->orderBy('start_at')
                        ->limit(200)
                        ->get(),
                ];

            case 'doctrine_evolution_report':
                return [
                    'kind' => 'doctrine_evolution_report',
                    'window_days' => $days,
                    'events' => DB::table('doctrine_evolution_events')
                        ->where('viewer_bloc_id', $blocId)
                        ->where('window_end', '>=', now()->subDays($days)->toDateString())
                        ->orderByDesc('magnitude')
                        ->limit(100)
                        ->get(),
                ];
        }
        return ['kind' => $kind];
    }

    private function renderMarkdown(array $payload, string $title): string
    {
        $md = "# {$title}\n\n";
        $kind = $payload['kind'] ?? 'unknown';

        if ($kind === 'operational_report') {
            $sev = $payload['severity_counts'] ?? [];
            $md .= "## Severity counts\n\n";
            foreach ($sev as $k => $v) {
                $md .= "- **{$k}**: {$v}\n";
            }
            $md .= "\n## Top incidents\n\n";
            foreach ($payload['top_incidents'] ?? [] as $i) {
                $md .= "- [{$i->severity}] {$i->primary_system_name} · {$i->start_at} · {$i->incident_type}\n";
            }
            $md .= "\n## Open alerts\n\n";
            foreach ($payload['open_alerts'] ?? [] as $a) {
                $md .= "- [{$a->severity}] {$a->title}\n";
            }
        } elseif ($kind === 'strategic_summary') {
            $md .= "Profile window: {$payload['profile_window_end']}\n\n";
            $md .= "## Coalitions\n\n";
            foreach ($payload['coalitions'] ?? [] as $c) {
                $md .= "- **{$c->bloc_display_name}** · {$c->alliance_count} alliances · {$c->incident_count} incidents · escalation {$c->escalation_rate}\n";
            }
            $md .= "\n## Top alliances\n\n";
            foreach ($payload['top_alliances'] ?? [] as $a) {
                $name = $a->alliance_name ?? "#{$a->alliance_id}";
                $md .= "- {$name} · {$a->operational_style} · {$a->incident_count} incidents\n";
            }
            $md .= "\n## Doctrine events\n\n";
            foreach ($payload['doctrine_events'] ?? [] as $d) {
                $aname = $d->alliance_name ?? '(unattributed)';
                $dname = $d->doctrine_name ?? '';
                $md .= "- {$d->event_type} · {$aname}" . ($dname ? " · {$dname}" : "") . " · Δ" . number_format((float) $d->magnitude, 2) . "\n";
            }
        } elseif ($kind === 'corridor_map') {
            $md .= "## Corridors (top by transitions)\n\n";
            foreach ($payload['corridors'] ?? [] as $c) {
                $md .= "- {$c->from_system_name} → {$c->to_system_name} · {$c->transition_count} transits · {$c->distinct_characters} chars · {$c->route_classification}\n";
            }
        } elseif ($kind === 'incident_timeline') {
            $md .= "## Incident timeline\n\n";
            foreach ($payload['incidents'] ?? [] as $i) {
                $md .= "- {$i->start_at} · [{$i->severity}] {$i->primary_system_name} · {$i->incident_type}\n";
            }
        } elseif ($kind === 'doctrine_evolution_report') {
            $md .= "## Doctrine evolution events\n\n";
            foreach ($payload['events'] ?? [] as $d) {
                $aname = $d->alliance_name ?? '(unattributed)';
                $dname = $d->doctrine_name ?? '';
                $md .= "- [{$d->event_type}] {$aname}" . ($dname ? " · {$dname}" : "")
                    . " · prior " . number_format((float) ($d->prior_share ?? 0), 2)
                    . " → cur " . number_format((float) ($d->current_share ?? 0), 2)
                    . " · Δ " . number_format((float) $d->magnitude, 2)
                    . " ({$d->confidence})\n";
            }
        }

        return $md;
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

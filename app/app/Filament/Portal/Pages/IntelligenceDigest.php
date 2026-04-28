<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/intelligence/daily — daily operational digest reader.
 *
 * Reads daily_operational_digest. Falls back to "no rows yet" when
 * the digest worker hasn't run for the requested window.
 */
class IntelligenceDigest extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $navigationLabel = 'Daily intel digest';

    protected static string|UnitEnum|null $navigationGroup = 'Daily ops';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Daily intel digest';

    protected static ?string $slug = 'intelligence/daily';

    protected string $view = 'filament.portal.pages.intelligence-digest';

    public string $window = 'last_24h';

    public ?string $digestDate = null;

    public function mount(): void
    {
        $window = (string) request()->query('window', 'last_24h');
        if (! in_array($window, ['today', 'last_24h', 'last_7d'], true)) {
            $window = 'last_24h';
        }
        $this->window = $window;

        $date = (string) request()->query('date', '');
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $this->digestDate = $date;
        }
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) {
            return ['no_bloc' => true];
        }
        $blocName = DB::table('coalition_blocs')->where('id', $blocId)->value('display_name')
            ?? "Bloc #{$blocId}";

        $date = $this->digestDate
            ?: (DB::table('daily_operational_digest')
                ->where('viewer_bloc_id', $blocId)
                ->where('window_kind', $this->window)
                ->max('digest_date') ?? now()->toDateString());

        $digest = DB::table('daily_operational_digest')
            ->where('viewer_bloc_id', $blocId)
            ->where('digest_date', $date)
            ->where('window_kind', $this->window)
            ->first();

        $availableDates = DB::table('daily_operational_digest')
            ->where('viewer_bloc_id', $blocId)
            ->where('window_kind', $this->window)
            ->orderByDesc('digest_date')
            ->limit(20)
            ->pluck('digest_date');

        if ($digest === null) {
            return [
                'no_bloc' => false,
                'no_digest' => true,
                'bloc_id' => $blocId,
                'bloc_name' => $blocName,
                'window' => $this->window,
                'date' => $date,
                'available_dates' => $availableDates,
            ];
        }

        $decode = static fn (?string $j): array => json_decode($j ?? '[]', true) ?: [];

        $sd = $decode($digest->escalation_summary_json) ?: [];
        $ms = $decode($digest->metric_summary_json) ?: [];
        $criticalLike = (int) (($sd['strategic'] ?? 0) + ($sd['escalation'] ?? 0) + ($sd['coalition_level'] ?? 0));
        $totalIncidents = (int) array_sum($sd);
        $details = [];
        if ($totalIncidents > 0) $details[] = "{$totalIncidents} incidents in window";
        if ($criticalLike > 0)   $details[] = "{$criticalLike} at strategic+ severity";
        $newCorr = (int) ($ms['new_corridor_count'] ?? 0);
        if ($newCorr > 0) $details[] = "{$newCorr} new corridor" . ($newCorr === 1 ? '' : 's');
        $docEv  = (int) ($ms['doctrine_event_count'] ?? 0);
        if ($docEv > 0) $details[] = "{$docEv} doctrine event" . ($docEv === 1 ? '' : 's');

        if ($criticalLike > 0) {
            $verdict = ['severity' => 'critical', 'headline' => 'Strategic-severity activity in window', 'details' => $details];
        } elseif ($totalIncidents > 0) {
            $verdict = ['severity' => 'elevated', 'headline' => 'Operational incidents in window', 'details' => $details];
        } elseif ($newCorr > 0 || $docEv > 0) {
            $verdict = ['severity' => 'warning', 'headline' => 'Corridor / doctrine movement observed', 'details' => $details];
        } else {
            $verdict = ['severity' => 'info', 'headline' => 'Quiet window — no notable incidents', 'details' => []];
        }

        return [
            'no_bloc' => false,
            'no_digest' => false,
            'bloc_id' => $blocId,
            'bloc_name' => $blocName,
            'window' => $this->window,
            'date' => $digest->digest_date,
            'verdict' => $verdict,
            'narrative_md' => $digest->narrative_md,
            'metric_summary' => $ms,
            'top_incident_ids' => $decode($digest->top_incident_ids_json),
            'severity_summary' => $sd,
            'doctrine_evolution' => $decode($digest->doctrine_evolution_json),
            'coalition_movement' => $decode($digest->coalition_movement_json),
            'new_corridors' => $decode($digest->new_corridors_json),
            'unusual_compositions' => $decode($digest->unusual_compositions_json),
            'emerging_operators' => $decode($digest->emerging_operators_json),
            'response_anomalies' => $decode($digest->response_anomalies_json),
            'top_threats' => $decode($digest->top_threat_systems_json),
            'section_confidence' => $decode($digest->section_confidence_json),
            'evidence_summary' => $decode($digest->evidence_summary_json),
            'source_reliability' => $decode($digest->source_reliability_json),
            'available_dates' => $availableDates,
            'generated_at' => $digest->generated_at,
            'freshness_state' => $digest->freshness_state,
            'source_window_start' => $digest->source_window_start,
            'source_window_end' => $digest->source_window_end,
        ];
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

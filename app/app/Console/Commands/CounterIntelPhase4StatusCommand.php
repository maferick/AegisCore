<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * counter-intel:phase4-status — quick read-only health check on the
 * four Phase 4 derived tables. Prints per-table row count, latest
 * computed_at / event_timestamp, and breakdown by enum where useful.
 *
 * Used as a debug companion to the Python --dry-run mode. Together
 * they let an operator check "what compute is showing" without
 * touching the dossier render path.
 */
class CounterIntelPhase4StatusCommand extends Command
{
    protected $signature = 'counter-intel:phase4-status
        {--bloc= : optional viewer bloc id filter}';

    protected $description = 'Print Phase 4 derived-table health summary.';

    public function handle(): int
    {
        $bloc = $this->option('bloc');
        $bloc = $bloc !== null && ctype_digit((string) $bloc) ? (int) $bloc : null;

        $this->info('Phase 4 status' . ($bloc ? " · bloc {$bloc}" : ' · all blocs'));
        $this->newLine();

        $this->reportEvents();

        $this->reportTimelines($bloc);
        $this->reportFleetWindows($bloc);
        $this->reportIntelReliability($bloc);
        $this->reportSessionCorrelation($bloc);
        return self::SUCCESS;
    }

    private function reportEvents(): void
    {
        $eventsTotal = DB::table('eve_log_events')->count();
        $files = DB::table('eve_log_files')->count();
        $clients = DB::table('eve_log_upload_clients')->count();
        $errors = DB::table('eve_log_parse_errors')->where('status', 'open')->count();
        $this->line("source · clients={$clients} files={$files} events={$eventsTotal} open_parse_errors={$errors}");
        $this->newLine();
    }

    private function reportTimelines(?int $bloc): void
    {
        $q = DB::table('operational_timeline_events');
        if ($bloc !== null) $q->where('viewer_bloc_id', $bloc);
        $total = (clone $q)->count();
        $this->line("operational_timeline_events · {$total} rows");
        if ($total === 0) { $this->newLine(); return; }
        $latest = (clone $q)->max('event_timestamp');
        $this->line("  latest event_timestamp: " . ($latest ?: '—'));
        $byType = (clone $q)
            ->groupBy('timeline_type')
            ->orderBy('timeline_type')
            ->select('timeline_type', DB::raw('COUNT(*) AS n'),
                DB::raw('MAX(event_timestamp) AS latest'))
            ->get();
        foreach ($byType as $r) {
            $this->line(sprintf('  %-22s %6d  latest %s',
                $r->timeline_type, $r->n, $r->latest ?? '—'));
        }
        $this->newLine();
    }

    private function reportFleetWindows(?int $bloc): void
    {
        $q = DB::table('fleet_presence_windows');
        if ($bloc !== null) $q->where('viewer_bloc_id', $bloc);
        $total = (clone $q)->count();
        $this->line("fleet_presence_windows · {$total} rows");
        if ($total === 0) { $this->newLine(); return; }
        $byRole = (clone $q)
            ->groupBy('derived_role')
            ->orderBy('derived_role')
            ->select('derived_role', DB::raw('COUNT(*) AS n'),
                DB::raw('AVG(participation_score) AS avg_score'))
            ->get();
        foreach ($byRole as $r) {
            $this->line(sprintf('  %-22s %6d  avg_score %.3f',
                $r->derived_role, $r->n, (float) ($r->avg_score ?? 0)));
        }
        $this->newLine();
    }

    private function reportIntelReliability(?int $bloc): void
    {
        $q = DB::table('intel_reliability_profiles');
        if ($bloc !== null) $q->where('viewer_bloc_id', $bloc);
        $total = (clone $q)->count();
        $this->line("intel_reliability_profiles · {$total} rows");
        if ($total === 0) { $this->newLine(); return; }
        $row = (clone $q)
            ->select(
                DB::raw('SUM(reports_submitted) AS reports'),
                DB::raw('SUM(confirmations) AS conf'),
                DB::raw('SUM(contradictions) AS contra'),
                DB::raw('AVG(reliability_score) AS avg_score'),
                DB::raw('MAX(computed_at) AS computed_at'),
            )
            ->first();
        $this->line(sprintf('  reports=%d confirmations=%d contradictions=%d avg_score=%.3f latest=%s',
            (int) $row->reports, (int) $row->conf, (int) $row->contra,
            (float) ($row->avg_score ?? 0), $row->computed_at ?? '—'));
        $byConfidence = (clone $q)
            ->groupBy('confidence')
            ->orderBy('confidence')
            ->select('confidence', DB::raw('COUNT(*) AS n'))
            ->get();
        foreach ($byConfidence as $r) {
            $this->line(sprintf('  confidence=%-12s %6d', $r->confidence, $r->n));
        }
        $this->newLine();
    }

    private function reportSessionCorrelation(?int $bloc): void
    {
        $q = DB::table('session_correlation_edges');
        if ($bloc !== null) $q->where('viewer_bloc_id', $bloc);
        $total = (clone $q)->count();
        $this->line("session_correlation_edges · {$total} rows");
        if ($total === 0) { $this->newLine(); return; }
        $byConfidence = (clone $q)
            ->groupBy('confidence')
            ->orderBy('confidence')
            ->select('confidence', DB::raw('COUNT(*) AS n'),
                DB::raw('AVG(correlation_score) AS avg_score'))
            ->get();
        foreach ($byConfidence as $r) {
            $this->line(sprintf('  confidence=%-12s %6d  avg_score %.3f',
                $r->confidence, $r->n, (float) ($r->avg_score ?? 0)));
        }
        $latest = (clone $q)->max('computed_at');
        $this->line('  latest computed_at: ' . ($latest ?? '—'));
        $this->newLine();
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\CounterIntel\Services\CombatAnomalyService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nightly recompute of ci_combat_anomalies for every counter-intel
 * review candidate in the selected bands.
 *
 * Reads candidate (character, viewer_bloc) pairs from the latest
 * ci_character_anomalies_rolling window, processes each in chunks
 * of 50 so one failure doesn't wipe out a full nightly pass.
 *
 * Idempotent: rerunning against the same window upserts into
 * ci_combat_anomalies keyed by (character_id, viewer_bloc_id,
 * window_end_date).
 */
class ComputeCombatAnomaliesCommand extends Command
{
    protected $signature = 'counter-intel:compute-combat-anomalies
        {--band=critical,high,elevated : comma-separated review_priority_band values to process}
        {--character= : process only this character_id (ops / smoke-test)}
        {--bloc= : process only this viewer_bloc_id}
        {--limit=0 : max candidates to process (0 = unlimited)}
        {--dry-run : compute but do not persist}';

    protected $description = 'Compute ci_combat_anomalies for counter-intel review candidates.';

    public function handle(CombatAnomalyService $service): int
    {
        $bands = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('band')))));
        if ($bands === []) $bands = ['critical', 'high', 'elevated'];

        $singleCharacter = $this->option('character') ? (int) $this->option('character') : null;
        $singleBloc = $this->option('bloc') ? (int) $this->option('bloc') : null;
        $limit = (int) $this->option('limit');

        $query = DB::table('ci_character_anomalies_rolling AS a')
            ->whereIn('a.review_priority_band', $bands)
            ->select('a.character_id', 'a.viewer_bloc_id', 'a.window_end_date');
        if ($singleCharacter !== null) $query->where('a.character_id', $singleCharacter);
        if ($singleBloc !== null) $query->where('a.viewer_bloc_id', $singleBloc);

        // Keep each (character, bloc) at the latest window only.
        $query->join(
            DB::raw('(SELECT character_id, viewer_bloc_id, MAX(window_end_date) AS mx FROM ci_character_anomalies_rolling GROUP BY character_id, viewer_bloc_id) m'),
            fn ($j) => $j->on('m.character_id', '=', 'a.character_id')
                ->on('m.viewer_bloc_id', '=', 'a.viewer_bloc_id')
                ->on('m.mx', '=', 'a.window_end_date'),
        );

        if ($limit > 0) $query->limit($limit);

        $candidates = $query->orderBy('a.review_priority_score', 'desc')->get();
        $total = $candidates->count();
        $this->info("Processing {$total} candidates (bands: " . implode(',', $bands) . ").");
        if ($total === 0) return self::SUCCESS;

        $dry = (bool) $this->option('dry-run');
        $processed = 0;
        $reinforces = 0;
        $weakens = 0;
        $neutral = 0;
        $insufficient = 0;

        foreach ($candidates as $c) {
            $charId = (int) $c->character_id;
            $blocId = (int) $c->viewer_bloc_id;
            $windowEnd = CarbonImmutable::parse((string) $c->window_end_date)->endOfDay();
            try {
                $row = $dry
                    ? $service->compute($charId, $blocId, $windowEnd)
                    : $service->computeAndStore($charId, $blocId, $windowEnd);
            } catch (\Throwable $e) {
                $this->warn("char {$charId} bloc {$blocId}: {$e->getMessage()}");
                continue;
            }
            $processed++;
            match ($row['combat_anomaly_band']) {
                'reinforces' => $reinforces++,
                'weakens' => $weakens++,
                'neutral' => $neutral++,
                'insufficient_data' => $insufficient++,
                default => null,
            };

            if ($processed % 50 === 0) {
                $this->info("  ... {$processed}/{$total}");
            }
        }

        $verb = $dry ? 'would write' : 'wrote';
        $this->info("Done. {$verb} {$processed} rows. reinforces={$reinforces} neutral={$neutral} weakens={$weakens} insufficient={$insufficient}");
        return self::SUCCESS;
    }
}

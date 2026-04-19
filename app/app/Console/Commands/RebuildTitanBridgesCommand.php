<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Precompute titan-bridge-range system pairs (≤ 6 LY, EVE scale).
 *
 * EVE in-game constant: 1 LY = 9.46e15 meters (per CCP dev docs).
 * Titan bridge range is 6 LY with no skill bonus.
 *
 * One-shot rebuild — ref_solar_systems xyz is SDE data and only
 * changes on CCP expansion-level releases. Idempotent: TRUNCATE
 * + INSERT.
 */
class RebuildTitanBridgesCommand extends Command
{
    protected $signature = 'map:rebuild-titan-bridges {--range-ly=6.0 : Max LY to keep}';

    protected $description = 'Precompute system pairs within titan bridge range (6 LY default).';

    public function handle(): int
    {
        $rangeLy = (float) $this->option('range-ly');
        $ly = 9.46e15;
        $maxMeters = $rangeLy * $ly;
        $maxMetersSq = $maxMeters * $maxMeters;

        $this->info("Loading solar systems with xyz coords…");
        $systems = DB::table('ref_solar_systems')
            ->whereNotNull('position_x')
            ->whereNotNull('position_y')
            ->whereNotNull('position_z')
            ->select('id', 'position_x', 'position_y', 'position_z')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'x' => (float) $r->position_x,
                'y' => (float) $r->position_y,
                'z' => (float) $r->position_z,
            ])
            ->all();

        $n = count($systems);
        $this->info(sprintf('Systems with coords: %d', $n));
        $pairs = 0;
        $batch = [];

        DB::statement('TRUNCATE TABLE system_titan_bridges');
        $bar = $this->output->createProgressBar($n);
        $bar->setFormat('%current%/%max% [%bar%] %percent:3s%%  %message%');
        $bar->start();

        // O(N^2) pair loop over coords in memory — ~25M iterations at
        // N=5000, ~2s in PHP. All math in squared meters to skip sqrt
        // until we know the pair passes the threshold.
        for ($i = 0; $i < $n; $i++) {
            $a = $systems[$i];
            for ($j = $i + 1; $j < $n; $j++) {
                $b = $systems[$j];
                $dx = $a['x'] - $b['x'];
                $dy = $a['y'] - $b['y'];
                $dz = $a['z'] - $b['z'];
                $dsq = $dx * $dx + $dy * $dy + $dz * $dz;
                if ($dsq > $maxMetersSq) continue;
                $distLy = sqrt($dsq) / $ly;
                [$lo, $hi] = $a['id'] < $b['id'] ? [$a['id'], $b['id']] : [$b['id'], $a['id']];
                $batch[] = [$lo, $hi, round($distLy, 4)];
                if (count($batch) >= 5000) {
                    $this->flush($batch);
                    $pairs += count($batch);
                    $batch = [];
                }
            }
            $bar->advance();
        }
        if ($batch !== []) {
            $this->flush($batch);
            $pairs += count($batch);
        }
        $bar->finish();
        $this->newLine(2);
        $this->info("Wrote {$pairs} pairs at ≤ {$rangeLy} LY");
        return 0;
    }

    /** @param list<array{0:int,1:int,2:float}> $batch */
    private function flush(array $batch): void
    {
        DB::table('system_titan_bridges')->insert(
            array_map(fn ($row) => [
                'from_system_id' => $row[0],
                'to_system_id' => $row[1],
                'ly_distance' => $row[2],
            ], $batch),
        );
    }
}

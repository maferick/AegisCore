<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Console;

use App\Domains\KillmailsBattleTheaters\Models\BattleTheater;
use App\Domains\KillmailsBattleTheaters\Services\AllegianceGraphService;
use App\Domains\KillmailsBattleTheaters\Services\BattleTheaterViewData;
use Illuminate\Console\Command;

/**
 * Back-populate the Neo4j allegiance graph from the resolver's output
 * on every *locked* battle theater.
 *
 * Unlocked theaters are skipped on purpose — their side assignment
 * can still shift as the clustering worker reclusters them, and we
 * don't want to bake an intermediate snapshot into the graph.
 *
 * Typical usage:
 *
 *   # one-off seed after deploy
 *   php artisan allegiance:backfill
 *
 *   # incremental — re-project the last week
 *   php artisan allegiance:backfill --since=7d
 *
 *   # targeted
 *   php artisan allegiance:backfill --theater=42505
 *
 * Idempotent: the upsert in AllegianceGraphService dedups on the
 * (theater_id, alliance pair) tuple, so running this twice on the
 * same set leaves the graph untouched.
 */
class BackfillAllegianceCommand extends Command
{
    protected $signature = 'allegiance:backfill
        {--since= : Only project theaters locked more recently than this (e.g. 7d, 24h)}
        {--theater= : Project a single theater by id (overrides --since)}
        {--dry-run : Print counts without writing to Neo4j}';

    protected $description = 'Project allegiance edges into Neo4j from locked battle theaters';

    public function handle(BattleTheaterViewData $viewBuilder, AllegianceGraphService $graph): int
    {
        $query = BattleTheater::query()->whereNotNull('locked_at');

        if ($theaterId = $this->option('theater')) {
            $query->where('id', (int) $theaterId);
        } elseif ($since = $this->option('since')) {
            $cutoff = $this->parseRelative((string) $since);
            if ($cutoff === null) {
                $this->error("--since must be Nd / Nh (e.g. 7d, 24h); got: {$since}");
                return 1;
            }
            $query->where('locked_at', '>=', $cutoff);
        }

        $total = (clone $query)->count();
        $this->info("Backfill scope: {$total} locked theater(s)");
        if ($total === 0) {
            return 0;
        }

        $dry = (bool) $this->option('dry-run');

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat('%current%/%max% [%bar%] %percent:3s%%  %message%');
        $bar->start();

        $edges = 0;
        $theatersWithSides = 0;

        // Chunk by id so we don't load 10k theaters into memory at once.
        $query->orderBy('id')->chunkById(100, function ($chunk) use (
            $viewBuilder, $graph, $dry, $bar, &$edges, &$theatersWithSides,
        ): void {
            foreach ($chunk as $theater) {
                $bar->setMessage("theater={$theater->id} sys=".($theater->primarySystem?->name ?? '?'));
                $bar->advance();

                $data = $viewBuilder->build($theater, viewer: null, hideBlocNames: true);
                $roster = $data['roster_by_side'];
                $sides = [];
                foreach (['A', 'B'] as $s) {
                    foreach ($roster[$s] ?? [] as $row) {
                        $aid = (int) ($row['alliance_id'] ?? 0);
                        if ($aid > 0) {
                            $sides[$aid] = $s;
                        }
                    }
                }
                if ($sides === []) {
                    continue;
                }
                $theatersWithSides++;
                $a = count(array_filter($sides, fn ($s) => $s === 'A'));
                $b = count(array_filter($sides, fn ($s) => $s === 'B'));
                // Every same-side pair plus every cross pair.
                $edges += (int) (($a * ($a - 1)) / 2 + ($b * ($b - 1)) / 2 + $a * $b);

                if (! $dry) {
                    $graph->recordForAllianceSides($theater->id, $sides);
                }
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            '%s: %d theaters contributed %d edges%s',
            $dry ? 'DRY-RUN' : 'done',
            $theatersWithSides,
            $edges,
            $dry ? ' (nothing written)' : '',
        ));

        return 0;
    }

    /**
     * Tiny relative-duration parser — covers the 'Nd' / 'Nh' cases
     * the CLI accepts without dragging in Carbon's full parser surface.
     */
    private function parseRelative(string $v): ?\Carbon\Carbon
    {
        if (! preg_match('/^(\d+)([dh])$/', $v, $m)) {
            return null;
        }
        $n = (int) $m[1];
        return $m[2] === 'd'
            ? now()->subDays($n)
            : now()->subHours($n);
    }
}

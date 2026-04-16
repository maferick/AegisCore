<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Jobs;

use App\Domains\KillmailsBattleTheaters\Actions\EnrichKillmail;
use App\Domains\KillmailsBattleTheaters\Data\EnrichmentBatchContext;
use App\Domains\KillmailsBattleTheaters\Models\Killmail;
use App\Domains\KillmailsBattleTheaters\Services\JitaValuationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Horizon job that enriches killmails for a specific month.
 *
 * Partitioned by month so multiple workers process different date
 * ranges in parallel. Each month is ShouldBeUnique so the same month
 * doesn't run twice, but different months run concurrently across
 * Horizon's worker pool.
 *
 * The scheduler dispatches one job per unenriched month. Each job
 * self-dispatches until its month is fully enriched.
 */
final class EnrichPendingKillmails implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const CHUNK_SIZE = 2000;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 60;

    private const NAME_RESOLVE_BACKLOG_THRESHOLD = 1000;

    /**
     * @param  string|null  $month  YYYY-MM to process, or null for oldest unenriched.
     */
    public function __construct(
        public readonly ?string $month = null,
    ) {}

    /**
     * Unique ID = the month, so different months run in parallel
     * but the same month doesn't overlap.
     */
    public function uniqueId(): string
    {
        return 'enrich:'.($this->month ?? 'auto');
    }

    public function handle(EnrichKillmail $action, JitaValuationService $valuations): void
    {
        $query = Killmail::unenriched()
            ->with(['items', 'attackers'])
            ->orderBy('killed_at')
            ->limit(self::CHUNK_SIZE);

        if ($this->month) {
            $start = $this->month.'-01';
            $end = date('Y-m-t', strtotime($start));
            $query->whereBetween('killed_at', [$start.' 00:00:00', $end.' 23:59:59']);
        }

        $killmails = $query->get();

        if ($killmails->isEmpty()) {
            return;
        }

        $pendingCount = Killmail::unenriched()->count();
        $resolveNames = $pendingCount <= self::NAME_RESOLVE_BACKLOG_THRESHOLD;

        // Hoist all the read-only lookups to batch level. Without this
        // each killmail's enrichment issues its own ref_item_types +
        // ref_solar_systems + market_history SELECT — roughly 5× chunk
        // size queries per job, which was the dominant cost when the
        // unenriched backlog sat at ~340k rows.
        $ctx = $this->buildBatchContext($killmails, $valuations);

        $enriched = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($killmails as $killmail) {
            try {
                $action->handle($killmail, resolveNames: $resolveNames, ctx: $ctx);
                $enriched++;
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), '1020')) {
                    $skipped++;
                } else {
                    $failed++;
                    Log::error('enrich-killmail: failed', [
                        'killmail_id' => $killmail->killmail_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('enrich-killmails: batch complete', [
            'month' => $this->month ?? 'auto',
            'enriched' => $enriched,
            'skipped' => $skipped,
            'failed' => $failed,
            'batch_size' => $killmails->count(),
        ]);

        // Self-dispatch immediately if more remain in this month.
        // No delay — the enrichment is pure DB work, no ESI calls.
        if ($killmails->count() === self::CHUNK_SIZE) {
            static::dispatch($this->month);
        }
    }

    /**
     * Build the batch context for a chunk: preload every
     * ref_item_types row referenced by any item or victim hull in the
     * chunk, every ref_solar_systems row referenced, and one
     * JitaValuationService::resolve() call per unique kill date. The
     * per-killmail enrichment path then reads from in-memory maps
     * instead of issuing fresh SELECTs. Round-trip cost collapses from
     * O(chunk_size) to O(1) for each of the three lookups.
     */
    private function buildBatchContext(Collection $killmails, JitaValuationService $valuations): EnrichmentBatchContext
    {
        // Collect type_ids + system_ids across the whole chunk.
        $typeIds = [];
        $systemIds = [];
        $dateTypeMap = [];

        foreach ($killmails as $km) {
            if ($km->victim_ship_type_id) {
                $typeIds[] = (int) $km->victim_ship_type_id;
            }
            foreach ($km->items as $item) {
                if ($item->type_id) {
                    $typeIds[] = (int) $item->type_id;
                }
            }
            if ($km->solar_system_id && (! $km->constellation_id || ! $km->region_id)) {
                $systemIds[] = (int) $km->solar_system_id;
            }

            $killDate = $km->killed_at->toDateString();
            if (! isset($dateTypeMap[$killDate])) {
                $dateTypeMap[$killDate] = [];
            }
            if ($km->victim_ship_type_id) {
                $dateTypeMap[$killDate][] = (int) $km->victim_ship_type_id;
            }
            foreach ($km->items as $item) {
                if ($item->type_id) {
                    $dateTypeMap[$killDate][] = (int) $item->type_id;
                }
            }
        }

        $typeIds = array_values(array_unique(array_filter($typeIds, fn (int $id) => $id > 0)));
        $systemIds = array_values(array_unique(array_filter($systemIds, fn (int $id) => $id > 0)));

        // One JOIN against ref_item_types / ref_item_groups /
        // ref_item_categories, keyed by type_id.
        $typeMetadata = [];
        if ($typeIds !== []) {
            $rows = DB::table('ref_item_types as t')
                ->leftJoin('ref_item_groups as g', 'g.id', '=', 't.group_id')
                ->leftJoin('ref_item_categories as c', 'c.id', '=', 'g.category_id')
                ->whereIn('t.id', $typeIds)
                ->get([
                    't.id as type_id',
                    't.name as type_name',
                    't.group_id',
                    'g.name as group_name',
                    'g.category_id',
                    'c.name as category_name',
                    't.meta_group_id',
                    't.meta_level',
                ]);
            foreach ($rows as $row) {
                $typeMetadata[(int) $row->type_id] = $row;
            }
        }

        // One SELECT against ref_solar_systems.
        $solarSystems = [];
        if ($systemIds !== []) {
            $rows = DB::table('ref_solar_systems')
                ->whereIn('id', $systemIds)
                ->get(['id', 'constellation_id', 'region_id']);
            foreach ($rows as $row) {
                $solarSystems[(int) $row->id] = $row;
            }
        }

        // One JitaValuationService::resolve() per unique kill date.
        // Each call does 2 queries (market_history + ref_item_types
        // fallback); collapsing to per-date instead of per-killmail
        // means ~30 calls per chunk instead of 2000.
        $valuationsByDate = [];
        foreach ($dateTypeMap as $dateStr => $ids) {
            $ids = array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));
            if ($ids === []) {
                continue;
            }
            $valuationsByDate[$dateStr] = $valuations->resolve(
                $ids,
                \Illuminate\Support\Carbon::parse($dateStr.' 12:00:00'),
            );
        }

        return new EnrichmentBatchContext(
            typeMetadata: $typeMetadata,
            solarSystems: $solarSystems,
            valuationsByDate: $valuationsByDate,
        );
    }

    /**
     * Dispatch one job per unenriched month. Called from the scheduler
     * or artisan command. Each month runs as its own unique job chain.
     */
    public static function dispatchAllMonths(): int
    {
        $months = DB::table('killmails')
            ->whereNull('enriched_at')
            ->selectRaw("DATE_FORMAT(killed_at, '%Y-%m') as month")
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('month');

        foreach ($months as $month) {
            static::dispatch($month);
        }

        return $months->count();
    }
}

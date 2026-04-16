<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Jobs;

use App\Services\Eve\Esi\EsiNameResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolves entity names from killmail participants into the shared
 * esi_entity_names cache via ESI /universe/names/.
 *
 * Strategy: grab a batch of recent killmails, collect all participant
 * IDs, filter out IDs already in the cache, then resolve the rest.
 * This avoids expensive NOT EXISTS subqueries against 15M+ attacker
 * rows.
 *
 * Rate-limited through the shared ESI client. Self-dispatches until
 * a batch comes back with nothing new to resolve.
 */
final class ResolveEntityNames implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Killmails to scan per batch for participant IDs. */
    private const KILLMAIL_BATCH = 200;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    /** Track position via a killmail_id cursor for sequential scanning. */
    public function __construct(
        public readonly ?int $afterKillmailId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'resolve-names';
    }

    public function handle(EsiNameResolver $resolver): void
    {
        // Grab a batch of killmails starting from the cursor position.
        $query = DB::table('killmails')
            ->select([
                'killmail_id',
                'victim_character_id',
                'victim_corporation_id',
                'victim_alliance_id',
            ])
            ->orderBy('killmail_id')
            ->limit(self::KILLMAIL_BATCH);

        if ($this->afterKillmailId) {
            $query->where('killmail_id', '>', $this->afterKillmailId);
        }

        $killmails = $query->get();

        if ($killmails->isEmpty()) {
            Log::info('resolve-entity-names: scan complete, wrapping around');
            // Wrap around to start — names from new ingestion.
            static::dispatch(null)->delay(now()->addMinutes(5));

            return;
        }

        $lastId = $killmails->last()->killmail_id;

        // Collect all entity IDs from victims.
        $entityIds = collect();
        foreach ($killmails as $km) {
            if ($km->victim_character_id) {
                $entityIds->push((int) $km->victim_character_id);
            }
            if ($km->victim_corporation_id) {
                $entityIds->push((int) $km->victim_corporation_id);
            }
            if ($km->victim_alliance_id) {
                $entityIds->push((int) $km->victim_alliance_id);
            }
        }

        // Collect attacker IDs for these killmails.
        $killmailIds = $killmails->pluck('killmail_id')->all();
        $attackers = DB::table('killmail_attackers')
            ->whereIn('killmail_id', $killmailIds)
            ->select(['character_id', 'corporation_id', 'alliance_id', 'faction_id'])
            ->get();

        foreach ($attackers as $att) {
            if ($att->character_id) {
                $entityIds->push((int) $att->character_id);
            }
            if ($att->corporation_id) {
                $entityIds->push((int) $att->corporation_id);
            }
            if ($att->alliance_id) {
                $entityIds->push((int) $att->alliance_id);
            }
            if ($att->faction_id) {
                $entityIds->push((int) $att->faction_id);
            }
        }

        $uniqueIds = $entityIds->unique()->filter(fn ($id) => $id > 0)->values();

        if ($uniqueIds->isEmpty()) {
            static::dispatch($lastId)->delay(now()->addSeconds(1));

            return;
        }

        // Filter out IDs already in the cache.
        $cached = DB::table('esi_entity_names')
            ->whereIn('entity_id', $uniqueIds->all())
            ->pluck('entity_id')
            ->flip();

        $uncached = $uniqueIds->reject(fn ($id) => $cached->has($id))->values()->all();

        if ($uncached === []) {
            // All cached — move to next batch quickly.
            static::dispatch($lastId)->delay(now()->addSeconds(1));

            return;
        }

        // Resolve uncached IDs via ESI (rate-limited).
        $resolved = $resolver->resolve($uncached);

        Log::info('resolve-entity-names: batch complete', [
            'scanned_killmails' => $killmails->count(),
            'unique_ids' => $uniqueIds->count(),
            'already_cached' => $cached->count(),
            'resolved' => count($resolved),
            'cursor' => $lastId,
        ]);

        // Self-dispatch to continue scanning.
        static::dispatch($lastId)->delay(now()->addSeconds(2));
    }
}

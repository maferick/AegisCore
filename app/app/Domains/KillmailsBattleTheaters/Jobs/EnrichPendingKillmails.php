<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Jobs;

use App\Domains\KillmailsBattleTheaters\Actions\EnrichKillmail;
use App\Domains\KillmailsBattleTheaters\Models\Killmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Horizon job that drains the unenriched killmail backlog.
 *
 * Eager-loads items + attackers for the whole batch in 2 queries (not
 * per-killmail), then enriches each killmail. The actual enrichment
 * work is ~2ms/kill (classify + value from cached queries), so the
 * batch size can be large.
 */
final class EnrichPendingKillmails implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Killmails per dispatch. Profiled at ~2ms/kill after eager load. */
    private const CHUNK_SIZE = 500;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    private const NAME_RESOLVE_BACKLOG_THRESHOLD = 1000;

    public function handle(EnrichKillmail $action): void
    {
        // Eager-load items + attackers for the whole batch in 2 queries
        // instead of 2 queries per killmail (the N+1 that was causing
        // 200 kills to take 60 seconds).
        $killmails = Killmail::unenriched()
            ->with(['items', 'attackers'])
            ->orderBy('killed_at')
            ->limit(self::CHUNK_SIZE)
            ->get();

        if ($killmails->isEmpty()) {
            return;
        }

        $pendingCount = Killmail::unenriched()->count();
        $resolveNames = $pendingCount <= self::NAME_RESOLVE_BACKLOG_THRESHOLD;

        $enriched = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($killmails as $killmail) {
            try {
                $action->handle($killmail, resolveNames: $resolveNames);
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
            'enriched' => $enriched,
            'skipped' => $skipped,
            'failed' => $failed,
            'batch_size' => $killmails->count(),
        ]);

        if ($killmails->count() === self::CHUNK_SIZE) {
            static::dispatch()->delay(now()->addSeconds(1));
        }
    }
}

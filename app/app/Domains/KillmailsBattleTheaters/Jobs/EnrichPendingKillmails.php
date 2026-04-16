<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Jobs;

use App\Domains\KillmailsBattleTheaters\Actions\EnrichKillmail;
use App\Domains\KillmailsBattleTheaters\Models\Killmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Horizon job that drains the unenriched killmail backlog.
 *
 * Processes killmails in FIFO order (oldest killed_at first), in chunks
 * of {@see CHUNK_SIZE}. Each killmail is enriched independently — one
 * failure does not block the batch. Self-dispatches with a short delay
 * if more unenriched killmails remain.
 *
 * Scheduling: dispatch from the Laravel scheduler on a cadence, or let
 * the self-dispatch loop drain the queue after bulk ingestion.
 */
final class EnrichPendingKillmails implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Killmails per dispatch. */
    private const CHUNK_SIZE = 50;

    public int $tries = 3;

    public int $timeout = 120;

    public function handle(EnrichKillmail $action): void
    {
        $killmails = Killmail::unenriched()
            ->orderBy('killed_at')
            ->limit(self::CHUNK_SIZE)
            ->get();

        if ($killmails->isEmpty()) {
            return;
        }

        $enriched = 0;
        $failed = 0;

        foreach ($killmails as $killmail) {
            try {
                $action->handle($killmail);
                $enriched++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error('enrich-killmail: failed', [
                    'killmail_id' => $killmail->killmail_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('enrich-killmails: batch complete', [
            'enriched' => $enriched,
            'failed' => $failed,
            'batch_size' => $killmails->count(),
        ]);

        // Self-dispatch if there may be more unenriched killmails.
        if ($killmails->count() === self::CHUNK_SIZE) {
            static::dispatch()->delay(now()->addSeconds(1));
        }
    }
}

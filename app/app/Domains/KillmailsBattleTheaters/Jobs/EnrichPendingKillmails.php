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
 * Processes killmails in FIFO order (oldest killed_at first), in chunks
 * of {@see CHUNK_SIZE}. Each killmail is enriched independently — one
 * failure does not block the batch. Self-dispatches with a short delay
 * if more unenriched killmails remain.
 *
 * Implements ShouldBeUnique so only one instance runs at a time across
 * all Horizon workers — prevents concurrent workers from grabbing the
 * same unenriched killmails and racing on item updates.
 */
final class EnrichPendingKillmails implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Killmails per dispatch. Each killmail touches ~70 rows. */
    private const CHUNK_SIZE = 200;

    public int $tries = 3;

    public int $timeout = 300;

    /** Unique lock TTL — release if the job hangs beyond this. */
    public int $uniqueFor = 600;

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

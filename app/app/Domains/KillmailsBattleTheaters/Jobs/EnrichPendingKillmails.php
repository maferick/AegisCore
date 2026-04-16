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
 * failure does not block the batch.
 *
 * Concurrent-safe with the Python backfill: if the backfill is actively
 * re-ingesting a killmail (DELETE + re-insert items), the enrichment
 * UPDATE hits MariaDB error 1020 ("Record has changed since last read").
 * These are silently skipped — the killmail stays unenriched and gets
 * picked up on the next pass after the backfill moves on.
 *
 * Implements ShouldBeUnique so only one instance runs at a time.
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

    /** Skip ESI name resolution when backlog exceeds this threshold. */
    private const NAME_RESOLVE_BACKLOG_THRESHOLD = 1000;

    public function handle(EnrichKillmail $action): void
    {
        $killmails = Killmail::unenriched()
            ->orderBy('killed_at')
            ->limit(self::CHUNK_SIZE)
            ->get();

        if ($killmails->isEmpty()) {
            return;
        }

        // Skip ESI name resolution during heavy backlog to avoid
        // hammering /universe/names/. Names get resolved once the
        // backlog is small enough for real-time enrichment.
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
                // MariaDB 1020 = "Record has changed since last read".
                // This means the Python backfill is actively re-ingesting
                // this killmail right now. Skip silently — it'll be
                // enriched on the next pass.
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

        // Self-dispatch if there may be more unenriched killmails.
        if ($killmails->count() === self::CHUNK_SIZE) {
            static::dispatch()->delay(now()->addSeconds(1));
        }
    }
}

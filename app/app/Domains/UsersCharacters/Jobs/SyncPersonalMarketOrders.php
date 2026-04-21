<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Jobs;

use App\Domains\UsersCharacters\Models\EveMarketToken;
use App\Domains\UsersCharacters\Services\PersonalMarketOrdersFetcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Walk every market token with the character-orders scope and sync
 * that character's personal orders + history. Failure for one
 * character never takes out the rest.
 *
 * Plane-boundary: bounded per-character (open + ≤ 90d history,
 * typically a few hundred rows at most). A user with main + 4 alts
 * all with tokens completes comfortably within the hourly window.
 */
class SyncPersonalMarketOrders implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(PersonalMarketOrdersFetcher $fetcher): void
    {
        $tokens = EveMarketToken::query()->get();
        if ($tokens->isEmpty()) {
            Log::debug('sync-personal-market-orders skipped — no tokens registered');
            return;
        }

        $ok = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($tokens as $token) {
            try {
                if (! $token->hasScope(PersonalMarketOrdersFetcher::SCOPE_REQUIRED)) {
                    $skipped++;
                    continue;
                }
                $counts = $fetcher->sync($token);
                $ok++;
                Log::info('personal orders sync ok', [
                    'character_id' => $token->character_id,
                    'open' => $counts['open'],
                    'history' => $counts['history'],
                ]);
            } catch (Throwable $e) {
                $failed++;
                Log::warning('personal orders sync failed', [
                    'character_id' => $token->character_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        Log::info('sync-personal-market-orders complete', [
            'ok' => $ok, 'skipped' => $skipped, 'failed' => $failed,
        ]);
    }
}

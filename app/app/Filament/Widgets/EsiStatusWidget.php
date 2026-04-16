<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/**
 * Admin dashboard widget: ESI rate limiter + cache status.
 *
 * Shows the current state of the ESI rate limit buckets and error
 * budget from the EsiRateLimiter's Redis keys, plus entity name
 * cache throughput.
 */
class EsiStatusWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'ESI Status';

    protected ?string $description = 'Rate limits, error budget, and cache.';

    protected static ?int $sort = 7;

    protected function getStats(): array
    {
        $store = Cache::store((string) config('eve.esi.cache_store', 'redis'));
        $now = time();
        $stats = [];

        // -- Error budget (global, 100/min) --
        $errorState = $store->get('esi:rl:error');
        if (is_array($errorState) && isset($errorState['remaining'], $errorState['reset_at'])) {
            $errRemaining = (int) $errorState['remaining'];
            $errResetAt = (int) $errorState['reset_at'];
            $errResetIn = max(0, $errResetAt - $now);

            $errorMargin = (int) config('eve.esi.error_limit_safety_margin', 10);
            $color = $errRemaining > 50 ? 'success' : ($errRemaining > $errorMargin ? 'warning' : 'danger');

            $stats[] = Stat::make('Error Budget', "{$errRemaining} / 100")
                ->description("Resets in {$errResetIn}s")
                ->color($color)
                ->icon('heroicon-o-shield-exclamation');
        } else {
            $stats[] = Stat::make('Error Budget', 'No data')
                ->description('No ESI calls recorded yet')
                ->color('gray')
                ->icon('heroicon-o-shield-exclamation');
        }

        // -- Rate limit groups (scan Redis for known groups) --
        $groupKeys = $this->scanGroupKeys($store);
        $activeGroups = 0;
        $totalRemaining = 0;
        $lowestGroup = null;
        $lowestRemaining = PHP_INT_MAX;

        foreach ($groupKeys as $group) {
            $state = $store->get('esi:rl:state:'.$group);
            if (! is_array($state) || ! isset($state['remaining'], $state['reset_at'])) {
                continue;
            }

            $remaining = (int) $state['remaining'];
            $resetAt = (int) $state['reset_at'];

            if ($resetAt <= $now) {
                continue; // Window expired, will reseed on next call.
            }

            $activeGroups++;
            $totalRemaining += $remaining;

            if ($remaining < $lowestRemaining) {
                $lowestRemaining = $remaining;
                $lowestGroup = $group;
            }
        }

        if ($activeGroups > 0) {
            $safetyMargin = (int) config('eve.esi.rate_limit_safety_margin', 5);
            $color = $lowestRemaining > 50 ? 'success' : ($lowestRemaining > $safetyMargin ? 'warning' : 'danger');

            $stats[] = Stat::make('Rate Limit', "{$lowestRemaining} tokens")
                ->description("{$activeGroups} active group(s), lowest: {$lowestGroup}")
                ->color($color)
                ->icon('heroicon-o-arrow-path');
        } else {
            $stats[] = Stat::make('Rate Limit', 'Idle')
                ->description('No active rate limit windows')
                ->color('gray')
                ->icon('heroicon-o-arrow-path');
        }

        // -- Global backoff --
        $globalBackoff = (int) ($store->get('esi:rl:backoff:_global') ?? 0);
        if ($globalBackoff > $now) {
            $waitSec = $globalBackoff - $now;
            $stats[] = Stat::make('Backoff', "{$waitSec}s")
                ->description('Global 429 cooldown active')
                ->color('danger')
                ->icon('heroicon-o-pause-circle');
        } else {
            $stats[] = Stat::make('Backoff', 'Clear')
                ->description('No active throttle')
                ->color('success')
                ->icon('heroicon-o-play-circle');
        }

        // -- Payload cache stats --
        $payloadCacheEnabled = (bool) config('eve.esi.payload_cache_enabled', true);
        $stats[] = Stat::make('Payload Cache', $payloadCacheEnabled ? 'Enabled' : 'Disabled')
            ->description($payloadCacheEnabled
                ? 'Stale-if-error: '.config('eve.esi.payload_stale_if_error_seconds', 600).'s'
                : 'Bypassed — bare transport active')
            ->color($payloadCacheEnabled ? 'success' : 'warning')
            ->icon('heroicon-o-server-stack');

        return $stats;
    }

    /**
     * Scan Redis for known ESI rate limit group state keys.
     *
     * @return list<string>  Group names (without the key prefix).
     */
    private function scanGroupKeys(mixed $store): array
    {
        // Try to access the underlying Redis connection to SCAN for keys.
        // If that's not possible (non-Redis store, etc.), return empty.
        try {
            $redis = $store->getStore()->connection();
            $prefix = config('database.redis.options.prefix', '');
            $pattern = $prefix.'esi:rl:state:*';
            $groups = [];

            $cursor = null;
            do {
                $results = $redis->scan($cursor, ['match' => $pattern, 'count' => 100]);
                if ($results === false) {
                    break;
                }
                foreach ($results as $key) {
                    $unprefixed = str_replace($prefix, '', $key);
                    $group = str_replace('esi:rl:state:', '', $unprefixed);
                    if ($group !== '' && $group !== '_global') {
                        $groups[] = $group;
                    }
                }
            } while ($cursor > 0);

            return array_unique($groups);
        } catch (\Throwable) {
            return [];
        }
    }
}

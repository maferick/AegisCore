<?php

declare(strict_types=1);

namespace App\Domains\Killmails\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetch zKillboard's totalValue + fittedValue for a single killmail.
 *
 * Used by the capital-value backfill so we can correct hull pricing
 * for titan/supercarrier/carrier/dread/FAX kills, where our EveRef-
 * driven pricing pipeline under-values hulls by 40-58% (audit
 * 2026-04-28). Per-call HTTP — caller is expected to throttle.
 *
 * No caching layer here — the backfill writes the result onto the
 * killmails row, so subsequent reads hit DB instead.
 */
final class ZkillKillmailValueService
{
    private const REQUEST_TIMEOUT_SECONDS = 10;
    private const USER_AGENT = 'AegisCore/0.1 (+ops@example.com; capital pricing audit)';

    /**
     * @return array{total: float, fitted: float}|null
     */
    public function fetch(int $killmailId): ?array
    {
        $url = "https://zkillboard.com/api/killID/{$killmailId}/";
        try {
            $resp = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept-Encoding' => 'gzip',
            ])
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->retry(2, 750)
                ->get($url);

            if (! $resp->successful()) {
                Log::warning('zkill killmail value fetch failed', [
                    'killmail_id' => $killmailId,
                    'status' => $resp->status(),
                ]);
                return null;
            }

            $body = $resp->json();
            if (! is_array($body) || $body === []) {
                return null;
            }
            $zkb = $body[0]['zkb'] ?? null;
            if (! is_array($zkb)) {
                return null;
            }

            $total = isset($zkb['totalValue']) ? (float) $zkb['totalValue'] : 0.0;
            $fitted = isset($zkb['fittedValue']) ? (float) $zkb['fittedValue'] : 0.0;
            if ($total <= 0.0) {
                return null;
            }
            return ['total' => $total, 'fitted' => $fitted];
        } catch (\Throwable $e) {
            Log::warning('zkill killmail value fetch exception', [
                'killmail_id' => $killmailId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}

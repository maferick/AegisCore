<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Jobs;

use App\Domains\KillmailsBattleTheaters\Actions\IngestEsiKillmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Catch-up pass for one solar system: asks zkill for kills in the
 * last `pastSeconds` and ingests any killmail_id we don't already
 * have. Used by the scheduler to close gaps left by R2Z2 drops
 * (big fights, feed backlog, lost sequences during stream stalls).
 *
 * Unique per system so the scheduler can dispatch one per active
 * system without the same system running twice concurrently.
 */
final class ZkillSystemCatchupJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Single-system timeout — zkill call + up to ~200 ESI fetches at ~15/sec. */
    public int $timeout = 180;

    public int $tries = 2;

    public int $uniqueFor = 600;

    private const ZKILL_BASE = 'https://zkillboard.com/api';

    private const ESI_BASE = 'https://esi.evetech.net/latest';

    private const USER_AGENT = 'AegisCore catchup admin@aegiscore.local';

    public function __construct(
        public readonly int $systemId,
        public readonly int $pastSeconds = 7200,
    ) {}

    public function uniqueId(): string
    {
        return 'zkill-catchup:'.$this->systemId;
    }

    public function handle(IngestEsiKillmail $ingest): void
    {
        // zkill requires pastSeconds to be a multiple of 3600.
        $past = intdiv(max(3600, $this->pastSeconds), 3600) * 3600;

        // zkill's API tolerance is ~1 request per 6s per IP. Workers
        // across different systems share the same IP, so a global
        // Redis gate with 6s TTL throttles every call to one-at-a-time
        // regardless of how many catchup jobs are in flight. SET NX EX
        // is atomic; losing the race means sleep 200ms + retry.
        $gateKey = 'zkill:global-gate';
        $gateWait = 0;
        while (! Redis::set($gateKey, (string) microtime(true), 'EX', 6, 'NX')) {
            usleep(200_000);
            $gateWait += 200;
            if ($gateWait > 30_000) {
                // 30s of contention probably means worker starvation,
                // not normal pacing — bail so we don't hold a worker
                // slot forever.
                Log::warning('zkill-catchup: gate timeout', ['system_id' => $this->systemId]);
                return;
            }
        }

        try {
            $resp = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept-Encoding' => 'gzip',
            ])->timeout(60)->get(sprintf(
                '%s/systemID/%d/pastSeconds/%d/',
                self::ZKILL_BASE, $this->systemId, $past,
            ));
        } catch (Throwable $e) {
            Log::warning('zkill-catchup: fetch failed', [
                'system_id' => $this->systemId, 'error' => $e->getMessage(),
            ]);
            return;
        }

        if (! $resp->ok()) {
            Log::warning('zkill-catchup: non-200', [
                'system_id' => $this->systemId, 'status' => $resp->status(),
            ]);
            return;
        }

        $entries = $resp->json() ?: [];
        if ($entries === []) {
            return;
        }

        $ids = array_map(static fn ($e) => (int) ($e['killmail_id'] ?? 0), $entries);
        $ids = array_filter($ids, fn ($id) => $id > 0);
        $existing = DB::table('killmails')->whereIn('killmail_id', $ids)->pluck('killmail_id')->all();
        $existingSet = array_flip($existing);

        $ingested = 0;
        $errors = 0;

        foreach ($entries as $e) {
            $kmId = (int) ($e['killmail_id'] ?? 0);
            $kmHash = (string) ($e['zkb']['hash'] ?? $e['hash'] ?? '');
            if ($kmId <= 0 || $kmHash === '') {
                continue;
            }
            if (isset($existingSet[$kmId])) {
                continue;
            }

            try {
                $esiResp = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout(15)
                    ->get(sprintf('%s/killmails/%d/%s/', self::ESI_BASE, $kmId, $kmHash));
            } catch (Throwable $exc) {
                $errors++;
                continue;
            }
            if (! $esiResp->ok()) {
                $errors++;
                continue;
            }
            $esi = $esiResp->json();
            if (! is_array($esi)) {
                $errors++;
                continue;
            }

            // Cheap local window filter — skip anything outside the
            // zkill `pastSeconds` the caller asked for. Defends
            // against zkill returning stale entries.
            $killedAt = Carbon::parse($esi['killmail_time'] ?? 'now');
            if ($killedAt->lt(now()->subSeconds($past + 300))) {
                continue;
            }

            try {
                $ingest->handle($esi, $kmHash);
                $ingested++;
            } catch (Throwable $exc) {
                $errors++;
                Log::warning('zkill-catchup: ingest failed', [
                    'km' => $kmId, 'error' => $exc->getMessage(),
                ]);
            }

            // Keep ESI request pace modest; the job timeout + the
            // per-system entry cap make this a soft knob, not a
            // safety-critical one.
            usleep(70_000); // 70 ms → ~14 req/sec
        }

        if ($ingested > 0 || $errors > 0) {
            Log::info('zkill-catchup: system done', [
                'system_id' => $this->systemId,
                'entries' => count($entries),
                'already_had' => count($existing),
                'ingested' => $ingested,
                'errors' => $errors,
            ]);
        }
    }
}

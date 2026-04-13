<?php

declare(strict_types=1);

namespace App\Reference\Jobs;

use App\Reference\Models\SdeVersionCheck;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HEAD the pinned SDE tarball URL, compare against the repo-pinned
 * version marker, record a drift check row.
 *
 * Runs inside the Laravel control plane (< 2s, one insert) — safe by the
 * plane-boundary rule. The actual SDE *import* is a Python job
 * dispatched via `make sde-import`; this class only reports drift.
 *
 * Scheduled daily from `routes/console.php`; also runnable on demand via
 * the `reference:check-sde-version` command or `make sde-check`.
 */
class CheckSdeVersion implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // Keep this cheap — one HTTP HEAD + one file read + one insert.
    public int $tries = 1;

    public int $timeout = 30;

    public function handle(): void
    {
        $checkedAt = Carbon::now();
        $url = (string) config('aegiscore.sde.source_url');
        $versionFile = (string) config('aegiscore.sde.version_file');
        $timeout = (int) config('aegiscore.sde.check_timeout_seconds', 10);

        $pinned = $this->readPinned($versionFile);

        $etag = null;
        $lastModified = null;
        $upstream = null;
        $httpStatus = null;
        $notes = null;

        try {
            $response = Http::timeout($timeout)
                ->withHeaders(['User-Agent' => 'AegisCore SDE version check'])
                ->head($url);

            $httpStatus = $response->status();
            $etag = $this->firstHeader($response, 'ETag');
            $lastModified = $this->firstHeader($response, 'Last-Modified');

            // Prefer a strong version identifier when CCP exposes one.
            // ETag (often quoted; strip quotes for display) → Last-Modified →
            // null. Anything CCP publishes in a header that looks version-y
            // can be folded in later without a schema change.
            $upstream = $etag !== null
                ? trim($etag, '"')
                : $lastModified;

            if (! $response->successful()) {
                $notes = "Upstream HEAD returned HTTP {$httpStatus}";
            }
        } catch (Throwable $e) {
            $notes = 'HEAD request failed: '.$e->getMessage();
            Log::warning('SDE version check failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        $bump = $pinned !== null
            && $upstream !== null
            && $pinned !== $upstream;

        SdeVersionCheck::create([
            'checked_at' => $checkedAt,
            'pinned_version' => $pinned,
            'upstream_version' => $upstream,
            'upstream_etag' => $etag,
            'upstream_last_modified' => $lastModified,
            'is_bump_available' => $bump,
            'http_status' => $httpStatus,
            'notes' => $notes,
        ]);
    }

    /**
     * Read the pinned version marker. Empty string or missing file both
     * mean "no snapshot loaded yet" — return null so downstream code
     * can distinguish that from an actual version string.
     */
    private function readPinned(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $trimmed = trim($content);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Header names are case-insensitive but Laravel preserves the cases
     * it sees. Do a case-insensitive lookup + return the first value.
     */
    private function firstHeader(\Illuminate\Http\Client\Response $response, string $name): ?string
    {
        foreach ($response->headers() as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Local cache for images.evetech.net assets.
 *
 *   /img/type/{id}            → ship/item icon
 *   /img/character/{id}       → character portrait
 *   /img/alliance/{id}        → alliance logo
 *   /img/corporation/{id}     → corp logo
 *
 * Optional size (32, 64, 128, 256, 512) via ?size=64 — default 64.
 *
 * On cache miss, fetches from CCP, writes to storage/app/eve-images/
 * and returns. Subsequent hits skip the upstream call. The HTTP
 * response carries `Cache-Control: public, max-age=604800, immutable`
 * so browsers + CDNs cache too — repeat visits don't even hit
 * Laravel.
 *
 * Frontend stays one URL hop away from CCP (no scraping concerns)
 * and the war-report renders with predictable latency even when
 * images.evetech.net is slow.
 */
final class EveImageProxyController extends Controller
{
    private const array KIND_TO_PATH = [
        'type' => ['types', 'icon'],
        'character' => ['characters', 'portrait'],
        'alliance' => ['alliances', 'logo'],
        'corporation' => ['corporations', 'logo'],
    ];

    private const array ALLOWED_SIZES = [32, 64, 128, 256, 512];

    public function show(string $kind, int $id): Response
    {
        if (! isset(self::KIND_TO_PATH[$kind]) || $id <= 0) {
            abort(404);
        }
        $size = (int) request()->query('size', 64);
        if (! in_array($size, self::ALLOWED_SIZES, true)) {
            $size = 64;
        }

        [$ccpKind, $endpoint] = self::KIND_TO_PATH[$kind];
        $cacheDir = storage_path("app/eve-images/$ccpKind");
        $file = "$cacheDir/{$id}_{$size}.png";

        if (! is_file($file) || filesize($file) < 100) {
            if (! is_dir($cacheDir)) {
                @mkdir($cacheDir, 0755, true);
            }
            $url = "https://images.evetech.net/$ccpKind/$id/$endpoint?size=$size";
            try {
                $resp = Http::timeout(8)->retry(2, 250)->get($url);
                if (! $resp->successful() || strlen($resp->body()) < 100) {
                    Log::info('eve-image-proxy miss', [
                        'kind' => $kind,
                        'id' => $id,
                        'size' => $size,
                        'status' => $resp->status(),
                    ]);
                    abort(404);
                }
                file_put_contents($file, $resp->body());
            } catch (\Throwable $e) {
                Log::warning('eve-image-proxy fetch failed', [
                    'kind' => $kind,
                    'id' => $id,
                    'size' => $size,
                    'error' => $e->getMessage(),
                ]);
                abort(502);
            }
        }

        return response(file_get_contents($file), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=604800, immutable',
            'X-Cache-Source' => 'aegiscore',
        ]);
    }
}

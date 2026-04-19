<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns the SVG activity-map partial for one character. Called from
 * the portal Dashboard via JS on page load so the main render stays
 * snappy — the map's BFS + titan-pair work runs asynchronously.
 *
 * Gated to the viewer's own linked characters so we don't leak a
 * tailored "where this pilot has been" view to everyone.
 */
class CharacterActivityMapController extends Controller
{
    public function show(Request $request, int $cid): Response
    {
        $user = Auth::user();
        if ($user === null) {
            abort(403);
        }
        $ownsCharacter = $user->characters()->where('character_id', $cid)->exists();
        if (! $ownsCharacter) {
            abort(403);
        }

        $payload = Cache::remember("portal.activity_map.{$cid}.v3", 600, fn () => $this->build($cid));
        return response()
            ->view('filament.portal.partials.activity-map', ['c' => $payload])
            ->withHeaders(['Cache-Control' => 'private, max-age=60']);
    }

    /**
     * @return array{active: array<int, array<string, mixed>>, neighbors: array<int, array<string, mixed>>, gates: list<list<int>>, titan: list<list<mixed>>}
     */
    private function build(int $cid): array
    {
        $activeSystems = DB::select(<<<'SQL'
            SELECT s.id, s.name, s.position2d_x AS x, s.position2d_y AS y,
                   s.security_status AS sec, s.region_id,
                   SUM(u.n) AS n
              FROM (
                SELECT k.solar_system_id AS sid, COUNT(*) AS n
                  FROM killmail_attackers ka
                  JOIN killmails k ON k.killmail_id=ka.killmail_id
                 WHERE ka.character_id=? AND k.killed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY k.solar_system_id
                UNION ALL
                SELECT k.solar_system_id AS sid, COUNT(*) AS n
                  FROM killmails k
                 WHERE k.victim_character_id=? AND k.killed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY k.solar_system_id
              ) u
              JOIN ref_solar_systems s ON s.id = u.sid
             WHERE s.position2d_x IS NOT NULL AND s.position2d_y IS NOT NULL
             GROUP BY s.id, s.name, s.position2d_x, s.position2d_y, s.security_status, s.region_id
             ORDER BY n DESC
             LIMIT 200
        SQL, [$cid, $cid]);

        $activeMap = [];
        foreach ($activeSystems as $r) {
            $activeMap[(int) $r->id] = [
                'id' => (int) $r->id,
                'name' => (string) $r->name,
                'x' => (float) $r->x,
                'y' => (float) $r->y,
                'sec' => $r->sec !== null ? (float) $r->sec : null,
                'region_id' => (int) $r->region_id,
                'n' => (int) $r->n,
                'active' => true,
            ];
        }
        if ($activeMap === []) {
            return ['active' => [], 'neighbors' => [], 'gates' => [], 'titan' => []];
        }

        // Lightweight fast path: only pull stargates for the active
        // systems themselves (bounded by at most ~200 * 6-ish gate
        // rows). No full adjacency load, no BFS, no titan pairs yet —
        // those live behind a "show more" toggle we can add later.
        $activeIds = array_keys($activeMap);
        $neighborMap = [];
        $gatePairs = [];
        if ($activeIds !== []) {
            $gateRows = DB::table('ref_stargates')
                ->whereIn('solar_system_id', $activeIds)
                ->whereNotNull('destination_system_id')
                ->select('solar_system_id', 'destination_system_id')
                ->get();
            $neighborIds = [];
            foreach ($gateRows as $r) {
                $neighborIds[(int) $r->destination_system_id] = true;
            }
            $neighborIds = array_keys($neighborIds);
            if ($neighborIds !== []) {
                DB::table('ref_solar_systems')
                    ->whereIn('id', $neighborIds)
                    ->whereNotNull('position2d_x')
                    ->select('id', 'name', 'position2d_x', 'position2d_y', 'security_status', 'region_id')
                    ->get()
                    ->each(function ($sys) use (&$neighborMap, $activeMap): void {
                        $sid = (int) $sys->id;
                        if (isset($activeMap[$sid])) return;
                        $neighborMap[$sid] = [
                            'id' => $sid,
                            'name' => (string) $sys->name,
                            'x' => (float) $sys->position2d_x,
                            'y' => (float) $sys->position2d_y,
                            'sec' => $sys->security_status !== null ? (float) $sys->security_status : null,
                            'region_id' => (int) $sys->region_id,
                            'n' => 0,
                            'active' => false,
                        ];
                    });
            }
            $shownFlip = array_flip(array_merge($activeIds, array_keys($neighborMap)));
            foreach ($gateRows as $r) {
                $a = (int) $r->solar_system_id;
                $b = (int) $r->destination_system_id;
                if (! isset($shownFlip[$a]) || ! isset($shownFlip[$b])) continue;
                $key = $a < $b ? "{$a}-{$b}" : "{$b}-{$a}";
                $gatePairs[$key] = [$a < $b ? $a : $b, $a < $b ? $b : $a];
            }
            $gatePairs = array_values($gatePairs);
        }

        return [
            'active' => array_values($activeMap),
            'neighbors' => array_values($neighborMap),
            'gates' => $gatePairs,
            'titan' => [],
        ];
    }
}

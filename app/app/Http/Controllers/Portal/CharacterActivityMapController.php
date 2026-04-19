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

        $adj = Cache::remember('map.stargate_adj.v1', 3600, function (): array {
            $a = [];
            DB::table('ref_stargates')
                ->whereNotNull('destination_system_id')
                ->select('solar_system_id', 'destination_system_id')
                ->get()
                ->each(function ($r) use (&$a): void {
                    $a[(int) $r->solar_system_id][(int) $r->destination_system_id] = true;
                });
            return $a;
        });

        $activeIds = array_keys($activeMap);
        $pathSystems = [];
        if (count($activeIds) >= 2) {
            $anchor = $activeIds[0];
            $parent = [$anchor => null];
            $depth = [$anchor => 0];
            $queue = [$anchor];
            $maxDepth = 40;
            while ($queue !== []) {
                $u = array_shift($queue);
                if (($depth[$u] ?? 0) >= $maxDepth) continue;
                foreach ($adj[$u] ?? [] as $v => $_) {
                    if (isset($parent[$v])) continue;
                    $parent[$v] = $u;
                    $depth[$v] = $depth[$u] + 1;
                    $queue[] = $v;
                }
            }
            foreach ($activeIds as $aid) {
                if ($aid === $anchor || ! isset($parent[$aid])) continue;
                $step = $aid;
                while ($step !== null) {
                    $pathSystems[$step] = true;
                    $step = $parent[$step] ?? null;
                }
            }
        }

        $hop1Seed = array_values(array_unique(array_merge($activeIds, array_keys($pathSystems))));
        $hop1 = [];
        foreach ($hop1Seed as $sid) {
            foreach ($adj[$sid] ?? [] as $v => $_) $hop1[$v] = true;
        }
        $allIds = array_values(array_unique(array_merge($activeIds, array_keys($pathSystems), array_keys($hop1))));
        if (count($allIds) > 800) {
            $allIds = array_values(array_unique(array_merge($activeIds, array_keys($pathSystems))));
        }

        $coords = DB::table('ref_solar_systems')
            ->whereIn('id', $allIds)
            ->whereNotNull('position2d_x')
            ->select('id', 'name', 'position2d_x', 'position2d_y', 'security_status', 'region_id')
            ->get();
        $neighborMap = [];
        foreach ($coords as $sys) {
            $sid = (int) $sys->id;
            if (isset($activeMap[$sid])) continue;
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
        }

        $shownIds = array_merge(array_keys($activeMap), array_keys($neighborMap));
        $shownFlip = array_flip($shownIds);
        $gatePairs = [];
        foreach ($shownIds as $a) {
            foreach ($adj[$a] ?? [] as $b => $_) {
                if (! isset($shownFlip[$b])) continue;
                $key = $a < $b ? "{$a}-{$b}" : "{$b}-{$a}";
                $gatePairs[$key] = [$a < $b ? $a : $b, $a < $b ? $b : $a];
            }
        }
        $gatePairs = array_values($gatePairs);

        $titanPairs = [];
        $activeIdsFlip = array_flip($activeIds);
        $rows = DB::table('system_titan_bridges')
            ->whereIn('from_system_id', $shownIds)
            ->whereIn('to_system_id', $shownIds)
            ->select('from_system_id', 'to_system_id', 'ly_distance')
            ->get();
        foreach ($rows as $r) {
            $a = (int) $r->from_system_id;
            $b = (int) $r->to_system_id;
            if (! isset($activeIdsFlip[$a]) && ! isset($activeIdsFlip[$b])) continue;
            $titanPairs[] = [$a, $b, (float) $r->ly_distance];
        }

        return [
            'active' => array_values($activeMap),
            'neighbors' => array_values($neighborMap),
            'gates' => $gatePairs,
            'titan' => $titanPairs,
        ];
    }
}

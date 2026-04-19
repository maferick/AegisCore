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

        // Every region with at least one active system now renders
        // COMPLETELY — all member systems, plus the full stargate
        // network inside the region. Gives a proper dotlan-style
        // region map per panel instead of a BFS halo around actives.
        $activeIds = array_keys($activeMap);
        $neighborMap = [];
        $gatePairs = [];
        if ($activeIds !== []) {
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

            // BFS from actives first so we can tag every system with
            // its hop distance — blade uses this to decide which
            // names to label.
            $depth = [];
            foreach ($activeIds as $aid) $depth[$aid] = 0;
            $queue = $activeIds;
            while ($queue !== []) {
                $u = array_shift($queue);
                $d = $depth[$u];
                if ($d >= 99) continue;
                foreach ($adj[$u] ?? [] as $v => $_) {
                    if (isset($depth[$v])) continue;
                    $depth[$v] = $d + 1;
                    $queue[] = $v;
                }
            }

            $regionIds = array_values(array_unique(array_column($activeMap, 'region_id')));
            $coords = DB::table('ref_solar_systems')
                ->whereIn('region_id', $regionIds)
                ->whereNotNull('position2d_x')
                ->select('id', 'name', 'position2d_x', 'position2d_y', 'security_status', 'region_id')
                ->get();
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
                    'hop' => (int) ($depth[$sid] ?? 99),
                ];
            }
            $shownFlip = array_flip(array_merge($activeIds, array_keys($neighborMap)));
            foreach ($shownFlip as $a => $_) {
                foreach ($adj[$a] ?? [] as $b => $__) {
                    if (! isset($shownFlip[$b])) continue;
                    $key = $a < $b ? "{$a}-{$b}" : "{$b}-{$a}";
                    $gatePairs[$key] = [$a < $b ? $a : $b, $a < $b ? $b : $a];
                }
            }
            $gatePairs = array_values($gatePairs);
        }

        // Sovereignty owner info per visible system. Joined to
        // esi_entity_names for display.
        $sovByCid = [];
        if ($activeIds !== []) {
            $shownSovIds = array_merge($activeIds, array_keys($neighborMap));
            $rows = DB::table('system_sovereignty AS s')
                ->leftJoin('esi_entity_names AS ea', function ($j): void {
                    $j->on('ea.entity_id', '=', 's.alliance_id')->where('ea.category', 'alliance');
                })
                ->whereIn('s.solar_system_id', $shownSovIds)
                ->whereNotNull('s.alliance_id')
                ->select('s.solar_system_id', 's.alliance_id', 'ea.name AS alliance_name')
                ->get();
            foreach ($rows as $r) {
                $sovByCid[(int) $r->solar_system_id] = [
                    'alliance_id' => (int) $r->alliance_id,
                    'alliance' => $r->alliance_name ? (string) $r->alliance_name : null,
                ];
            }
        }

        // Ansiblex corridors — static player-owned jump bridges,
        // populated via map:import-ansiblex. One row per pair (lo<hi).
        // We pass the full set down; blade filters per-region.
        $ansiblexPairs = [];
        if ($activeIds !== []) {
            $shownIds = array_merge($activeIds, array_keys($neighborMap));
            $shownFlipAns = array_flip($shownIds);
            DB::table('ansiblex_jump_bridges')
                ->whereIn('from_system_id', $shownIds)
                ->whereIn('to_system_id', $shownIds)
                ->select('from_system_id', 'to_system_id', 'name')
                ->get()
                ->each(function ($r) use (&$ansiblexPairs, $shownFlipAns): void {
                    $a = (int) $r->from_system_id;
                    $b = (int) $r->to_system_id;
                    if (! isset($shownFlipAns[$a]) || ! isset($shownFlipAns[$b])) return;
                    $ansiblexPairs[] = [$a, $b, $r->name ? (string) $r->name : null];
                });
        }

        // Group by region so visually disconnected clusters render as
        // separate per-region sub-maps instead of one wide map with
        // empty space between.
        $regionIds = array_values(array_unique(array_column($activeMap, 'region_id')));
        $regionNames = DB::table('ref_regions')
            ->whereIn('id', $regionIds)
            ->pluck('name', 'id')
            ->all();

        $regions = [];
        foreach ($regionIds as $rid) {
            $regionActive = array_filter($activeMap, fn ($s) => (int) $s['region_id'] === (int) $rid);
            $regionNeighborIds = [];
            foreach ($regionActive as $s) {
                $regionNeighborIds[$s['id']] = true;
            }
            // Neighbors for THIS region's active set only.
            $regionNeighbors = array_filter($neighborMap, function ($n) use ($regionActive): bool {
                foreach ($regionActive as $s) {
                    if ((int) $n['region_id'] === (int) $s['region_id']) return true;
                }
                return false;
            });
            $regionShownIds = array_flip(array_merge(
                array_keys($regionActive),
                array_keys($regionNeighbors),
            ));
            $regionGates = array_values(array_filter($gatePairs, fn ($p) => isset($regionShownIds[$p[0]]) && isset($regionShownIds[$p[1]])));
            $regionAnsiblex = array_values(array_filter($ansiblexPairs, fn ($p) => isset($regionShownIds[$p[0]]) && isset($regionShownIds[$p[1]])));
            $regionSov = [];
            foreach ($regionShownIds as $sid => $_) {
                if (isset($sovByCid[$sid])) $regionSov[$sid] = $sovByCid[$sid];
            }
            $regions[] = [
                'id' => (int) $rid,
                'name' => (string) ($regionNames[$rid] ?? "Region #{$rid}"),
                'active' => array_values($regionActive),
                'neighbors' => array_values($regionNeighbors),
                'gates' => $regionGates,
                'ansiblex' => $regionAnsiblex,
                'sov' => $regionSov,
            ];
        }
        // Sort regions so the one with the most kills renders first.
        usort($regions, fn ($a, $b) => array_sum(array_column($b['active'], 'n')) <=> array_sum(array_column($a['active'], 'n')));

        return [
            'regions' => $regions,
            'active_count' => count($activeMap),
            'neighbor_count' => count($neighborMap),
        ];
    }
}

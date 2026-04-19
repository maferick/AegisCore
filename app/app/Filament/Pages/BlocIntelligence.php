<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /admin/bloc-intel — alliance-pair behavior observatory.
 *
 * Pick an alliance, see its relationship map derived from 90d of
 * killmails: affinity, hostility, confidence, inferred label, top
 * conditional triggers per counterpart. Viewer-agnostic — any alliance
 * can be the anchor.
 *
 * Renders ONLY from alliance_pair_behavior_rolling +
 * alliance_pair_conditional_triggers_rolling, plus esi_entity_names for
 * display. Ground-truth coalition labels shown as overlay where
 * available but never drive the scoring.
 */
class BlocIntelligence extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Bloc Intel';

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?int $navigationSort = 50;

    protected static ?string $title = 'Bloc Intel · Behavioral Observatory';

    protected static ?string $slug = 'bloc-intel';

    protected string $view = 'filament.pages.bloc-intel';

    public ?int $allianceId = null;
    public ?string $allianceSearch = null;

    public function mount(): void
    {
        $aid = request()->query('alliance_id');
        if ($aid !== null && ctype_digit((string) $aid)) {
            $this->allianceId = (int) $aid;
        }
        $this->allianceSearch = (string) request()->query('q', '');
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $windowEnd = DB::table('alliance_pair_behavior_rolling')
            ->max('window_end_date');
        if ($windowEnd === null) {
            return ['no_data' => true];
        }

        $suggestions = $this->suggestions($windowEnd, $this->allianceSearch);

        if ($this->allianceId === null) {
            return [
                'no_data' => false,
                'window_end' => $windowEnd,
                'alliance' => null,
                'pairs' => [],
                'triggers' => [],
                'suggestions' => $suggestions,
                'alliance_search' => $this->allianceSearch,
            ];
        }

        $alliance = DB::table('esi_entity_names')
            ->where('entity_id', $this->allianceId)
            ->where('category', 'alliance')
            ->first();
        $blocLabel = DB::table('coalition_entity_labels AS cel')
            ->leftJoin('coalition_blocs AS cb', 'cb.id', '=', 'cel.bloc_id')
            ->leftJoin('coalition_relationship_types AS crt', 'crt.id', '=', 'cel.relationship_type_id')
            ->where('cel.entity_type', 'alliance')
            ->where('cel.entity_id', $this->allianceId)
            ->where('cel.is_active', 1)
            ->select('cb.display_name AS bloc_name', 'crt.display_name AS role')
            ->first();

        $pairs = $this->pairsFor($this->allianceId, $windowEnd);
        $triggers = $this->triggersFor($this->allianceId, $windowEnd, $pairs);

        return [
            'no_data' => false,
            'window_end' => $windowEnd,
            'alliance' => [
                'id' => $this->allianceId,
                'name' => $alliance->name ?? "Alliance #{$this->allianceId}",
                'bloc' => $blocLabel->bloc_name ?? null,
                'role' => $blocLabel->role ?? null,
            ],
            'pairs' => $pairs,
            'triggers' => $triggers,
            'suggestions' => $suggestions,
            'alliance_search' => $this->allianceSearch,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function suggestions(string $windowEnd, ?string $search): array
    {
        $q = DB::table('alliance_pair_behavior_rolling AS p')
            ->where('p.window_end_date', $windowEnd)
            ->selectRaw('
                CASE WHEN p.alliance_a_id IS NOT NULL THEN p.alliance_a_id END AS aid,
                SUM(p.n_obs) AS total_n_obs
            ')
            ->groupBy('p.alliance_a_id');
        // Union with alliance_b_id to cover both positions.
        $all = DB::query()->fromSub(
            DB::table('alliance_pair_behavior_rolling')
                ->where('window_end_date', $windowEnd)
                ->selectRaw('alliance_a_id AS aid, n_obs')
                ->unionAll(
                    DB::table('alliance_pair_behavior_rolling')
                        ->where('window_end_date', $windowEnd)
                        ->selectRaw('alliance_b_id AS aid, n_obs')
                ),
            'u'
        )
            ->selectRaw('u.aid, SUM(u.n_obs) AS total_n_obs')
            ->groupBy('u.aid');

        $sub = $all;
        $rows = DB::query()->fromSub($sub, 'x')
            ->leftJoin('esi_entity_names AS en', function ($j): void {
                $j->on('en.entity_id', '=', 'x.aid')->where('en.category', 'alliance');
            })
            ->when($search, fn ($qq) => $qq->where('en.name', 'like', '%'.$search.'%'))
            ->orderByDesc('x.total_n_obs')
            ->limit(40)
            ->select('x.aid AS alliance_id', 'en.name', 'x.total_n_obs')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pairsFor(int $allianceId, string $windowEnd): array
    {
        $rows = DB::table('alliance_pair_behavior_rolling AS p')
            ->where('p.window_end_date', $windowEnd)
            ->where(function ($q) use ($allianceId): void {
                $q->where('p.alliance_a_id', $allianceId)
                  ->orWhere('p.alliance_b_id', $allianceId);
            })
            ->where('p.confidence', '>=', 0.3)  // hide near-noise rows
            ->selectRaw('
                CASE WHEN p.alliance_a_id = ? THEN p.alliance_b_id ELSE p.alliance_a_id END AS counterpart_id,
                p.alliance_a_id, p.alliance_b_id,
                p.n_obs, p.weighted_n_obs,
                p.affinity_score, p.hostility_score, p.confidence,
                p.last_seen_at
            ', [$allianceId])
            ->get();
        $ids = $rows->pluck('counterpart_id')->unique()->values()->all();
        $names = $ids
            ? DB::table('esi_entity_names')->whereIn('entity_id', $ids)->where('category', 'alliance')->pluck('name', 'entity_id')->all()
            : [];
        $blocLabels = $ids
            ? DB::table('coalition_entity_labels AS cel')
                ->leftJoin('coalition_blocs AS cb', 'cb.id', '=', 'cel.bloc_id')
                ->whereIn('cel.entity_id', $ids)
                ->where('cel.entity_type', 'alliance')
                ->where('cel.is_active', 1)
                ->select('cel.entity_id', 'cb.display_name AS bloc_name')
                ->get()->keyBy('entity_id')->all()
            : [];
        $out = [];
        foreach ($rows as $r) {
            $cid = (int) $r->counterpart_id;
            $out[] = [
                'counterpart_id' => $cid,
                'counterpart_name' => $names[$cid] ?? "Alliance #{$cid}",
                'counterpart_bloc' => $blocLabels[$cid]->bloc_name ?? null,
                'affinity' => (float) $r->affinity_score,
                'hostility' => (float) $r->hostility_score,
                'confidence' => (float) $r->confidence,
                'n_obs' => (int) $r->n_obs,
                'weighted_n_obs' => (float) $r->weighted_n_obs,
                'last_seen_at' => $r->last_seen_at,
                'label' => $this->deriveLabel((float) $r->affinity_score, (float) $r->hostility_score, (float) $r->confidence, (int) $r->n_obs),
            ];
        }
        // Sort: high-affinity + high-hostility highlighted; low-confidence
        // pushed down. Order by label bucket priority then by confidence.
        usort($out, function ($a, $b) {
            $order = ['aligned' => 0, 'hostile' => 1, 'conditionally aligned' => 2, 'loosely coordinated' => 3, 'neutral' => 4, 'insufficient observations' => 5];
            $oa = $order[$a['label']] ?? 9;
            $ob = $order[$b['label']] ?? 9;
            if ($oa !== $ob) return $oa <=> $ob;
            return $b['confidence'] <=> $a['confidence'];
        });
        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $pairs
     * @return array<int, list<array<string, mixed>>>  counterpart_id → triggers
     */
    private function triggersFor(int $allianceId, string $windowEnd, array $pairs): array
    {
        if (empty($pairs)) return [];
        $counterpartIds = array_column($pairs, 'counterpart_id');
        $rows = DB::table('alliance_pair_conditional_triggers_rolling AS t')
            ->where('t.window_end_date', $windowEnd)
            ->where(function ($q) use ($allianceId, $counterpartIds): void {
                $q->where(function ($q2) use ($allianceId, $counterpartIds): void {
                    $q2->where('t.alliance_a_id', $allianceId)
                       ->whereIn('t.alliance_b_id', $counterpartIds);
                })->orWhere(function ($q2) use ($allianceId, $counterpartIds): void {
                    $q2->where('t.alliance_b_id', $allianceId)
                       ->whereIn('t.alliance_a_id', $counterpartIds);
                });
            })
            ->where('t.confidence', '>=', 0.3)
            ->orderByRaw('ABS(t.conditional_delta) DESC')
            ->get();
        $triggerIds = $rows->pluck('trigger_alliance_id')->unique()->values()->all();
        $names = $triggerIds
            ? DB::table('esi_entity_names')->whereIn('entity_id', $triggerIds)->where('category', 'alliance')->pluck('name', 'entity_id')->all()
            : [];
        $out = [];
        foreach ($rows as $r) {
            $counterpart = (int) ($r->alliance_a_id === $allianceId ? $r->alliance_b_id : $r->alliance_a_id);
            $out[$counterpart] = $out[$counterpart] ?? [];
            if (count($out[$counterpart]) >= 3) continue;  // top 3 per pair
            $tid = (int) $r->trigger_alliance_id;
            $out[$counterpart][] = [
                'trigger_id' => $tid,
                'trigger_name' => $names[$tid] ?? "Alliance #{$tid}",
                'delta' => (float) $r->conditional_delta,
                'with_rate' => (float) $r->same_side_rate_with,
                'without_rate' => (float) $r->same_side_rate_without,
                'confidence' => (float) $r->confidence,
            ];
        }
        return $out;
    }

    private function deriveLabel(float $affinity, float $hostility, float $confidence, int $nObs): string
    {
        if ($nObs < 10) return 'insufficient observations';
        if ($confidence < 0.4) return 'insufficient observations';
        if ($affinity >= 0.85 && $hostility < 0.10) return 'aligned';
        if ($hostility >= 0.70) return 'hostile';
        if ($affinity >= 0.50 && $hostility < 0.20) return 'loosely coordinated';
        if ($affinity < 0.20 && $hostility < 0.20) return 'neutral';
        return 'conditionally aligned';
    }
}

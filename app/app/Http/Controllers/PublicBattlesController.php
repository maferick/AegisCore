<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\KillmailsBattleTheaters\Models\BattleTheater;
use App\Domains\KillmailsBattleTheaters\Services\BattleTheaterViewData;
use Illuminate\View\View;

/**
 * Public battle theater pages (`/battles` + `/battles/{id}`).
 *
 * Same data as the authed Filament Portal page — both call
 * ``BattleTheaterViewData::build()`` — but this controller passes
 * ``hideBlocNames=true`` so unauth viewers never see coalition bloc
 * labels (internal intel). Alliance / corp / character names come
 * from killmail data and are already public via zkillboard.
 *
 * Theater *generation* (clustering jobs, manual re-cluster actions)
 * stays behind auth — this controller is read-only.
 */
class PublicBattlesController
{
    public function index(?string $conflict = null): View
    {
        $query = BattleTheater::query()
            ->with(['primarySystem:id,name,security_status', 'region:id,name'])
            // Listing threshold: require ≥ 2 alliances with ≥ 20 pilots
            // each — filters out solo / small roams that don't read as
            // "battles", without counting third parties.
            ->whereIn('battle_theaters.id', function ($sub): void {
                $sub->select('theater_id')
                    ->from(\Illuminate\Support\Facades\DB::raw('(SELECT theater_id, alliance_id
                                        FROM battle_theater_participants
                                       WHERE alliance_id > 0
                                       GROUP BY theater_id, alliance_id
                                      HAVING COUNT(DISTINCT character_id) >= 20
                                     ) sides'))
                    ->groupBy('theater_id')
                    ->havingRaw('COUNT(*) >= 2');
            });

        // Conflict scope — restrict to theaters that had ≥ 1 war-
        // attributable killmail for the chosen conflict. Theater id
        // set is cached per-conflict by WarReport::warTheaterIds().
        $scopedConflict = null;
        $scopedLabel = null;
        if ($conflict !== null && isset(\App\Filament\Portal\Pages\WarReport::CONFLICTS[$conflict])) {
            $theaterIds = \App\Filament\Portal\Pages\WarReport::warTheaterIds($conflict);
            $query->whereIn('battle_theaters.id', $theaterIds === [] ? [-1] : $theaterIds);
            $scopedConflict = $conflict;
            $scopedLabel = \App\Filament\Portal\Pages\WarReport::CONFLICTS[$conflict]['opposing_label'];
        }

        $battles = $query->orderByDesc('end_time')->limit(100)->get();

        return view('public.battles.index', [
            'battles' => $battles,
            'scoped_conflict' => $scopedConflict,
            'scoped_label' => $scopedLabel,
        ]);
    }

    public function show(string $record, BattleTheaterViewData $builder): View
    {
        $theater = $this->resolveTheater($record);

        $data = $builder->build($theater, viewer: null, hideBlocNames: true);

        return view('public.battles.show', $data);
    }

    /**
     * Accept either a numeric id (legacy share URLs) or a stable
     * public_slug (preferred; survives clustering re-passes). When
     * the slug resolves to multiple rows — possible if the clusterer
     * split one fight into two with the same system+minute bucket —
     * pick the newest row so the user always lands on the current
     * view of that fight.
     */
    private function resolveTheater(string $record): BattleTheater
    {
        $query = BattleTheater::query()
            ->with(['primarySystem:id,name,security_status', 'region:id,name']);

        if (ctype_digit($record)) {
            $theater = $query->clone()->find((int) $record);
            if ($theater) {
                return $theater;
            }
        }

        return $query->where('public_slug', $record)
            ->orderByDesc('id')
            ->firstOrFail();
    }
}

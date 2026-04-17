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
    public function index(): View
    {
        $battles = BattleTheater::query()
            ->with(['primarySystem:id,name,security_status', 'region:id,name'])
            ->orderByDesc('end_time')
            ->limit(50)
            ->get();

        return view('public.battles.index', ['battles' => $battles]);
    }

    public function show(int $record, BattleTheaterViewData $builder): View
    {
        $theater = BattleTheater::query()
            ->with(['primarySystem:id,name,security_status', 'region:id,name'])
            ->findOrFail($record);

        $data = $builder->build($theater, viewer: null, hideBlocNames: true);

        return view('public.battles.show', $data);
    }
}

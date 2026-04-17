<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\KillmailsBattleTheaters\Models\Killmail;
use App\Domains\KillmailsBattleTheaters\Services\KillmailViewData;
use Illuminate\View\View;

/**
 * Public killmail detail page (`/kills/{id}`).
 *
 * Same data as the authed Filament portal killmail page — both call
 * ``KillmailViewData::build()``. Killmail contents (attackers,
 * victim, fitted items, prices) are already public via zkillboard's
 * RedisQ, so there's no viewer-specific intel here to gate.
 */
class PublicKillmailsController
{
    public function show(int $record, KillmailViewData $builder): View
    {
        $km = Killmail::with(['attackers', 'items'])->findOrFail($record);

        return view('public.kills.show', $builder->build($km));
    }
}

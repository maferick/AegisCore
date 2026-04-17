<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\KillmailsBattleTheaters\Models\BattleTheater;
use App\Domains\KillmailsBattleTheaters\Models\BattleTheaterSideOverride;
use App\Domains\KillmailsBattleTheaters\Services\AllegianceGraphService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Side override endpoints. Authed-only — guests viewing the public
 * /battles pages can't touch the overrides, but the Filament portal
 * page embeds the form so any signed-in operator can mark an
 * alliance / corp / character as Side A/B/C or "exclude" on a given
 * theater.
 *
 * Writes are upserts keyed by (theater_id, entity_type, entity_id).
 * Same entity can hold different side labels in different theaters —
 * we don't propagate overrides across fights because allegiance
 * shifts over time (Fraternity could be with WinterCo this week and
 * against them next).
 */
class BattleTheaterOverrideController
{
    public function __construct(private readonly AllegianceGraphService $graph) {}

    public function store(Request $request, int $record): RedirectResponse
    {
        $data = $request->validate([
            'entity_type' => 'required|in:'.implode(',', BattleTheaterSideOverride::entityTypes()),
            'entity_id' => 'required|integer|min:1',
            'side' => 'required|in:'.implode(',', BattleTheaterSideOverride::sides()),
        ]);

        // Theater existence check — guards against crafted POSTs
        // against reclustered / gone IDs. Stale override rows would
        // be harmless (resolver reads by theater_id and the theater
        // row is gone anyway) but the FK-style guard keeps the table
        // clean.
        BattleTheater::query()->findOrFail($record);

        BattleTheaterSideOverride::updateOrCreate(
            [
                'theater_id' => $record,
                'entity_type' => $data['entity_type'],
                'entity_id' => $data['entity_id'],
            ],
            [
                'side' => $data['side'],
                'actor_user_id' => Auth::id(),
            ],
        );

        // Project the new allegiance picture into the Neo4j
        // historical-allegiance graph. Best-effort: the service
        // swallows Neo4j failures so operator feedback never hinges
        // on the graph being up.
        $this->graph->recordForTheater($record);

        return back()->with('status', 'override saved');
    }

    public function destroy(Request $request, int $record): RedirectResponse
    {
        $data = $request->validate([
            'entity_type' => 'required|in:'.implode(',', BattleTheaterSideOverride::entityTypes()),
            'entity_id' => 'required|integer|min:1',
        ]);

        BattleTheaterSideOverride::query()
            ->where('theater_id', $record)
            ->where('entity_type', $data['entity_type'])
            ->where('entity_id', $data['entity_id'])
            ->delete();

        // Re-project: the remaining overrides may have shrunk the
        // set of high-confidence allegiance signals for this theater.
        // We don't attempt to remove previously-recorded edges
        // (decay handles stale data over time), but re-emitting
        // leaves the latest ``last_seen`` + ``theaters`` list honest.
        $this->graph->recordForTheater($record);

        return back()->with('status', 'override cleared');
    }
}

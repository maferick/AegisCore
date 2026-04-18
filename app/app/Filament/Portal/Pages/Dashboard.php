<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $title = 'Overview';

    protected ?string $heading = 'My Overview';

    protected ?string $subheading = 'Character summary and recent activity.';

    protected string $view = 'filament.portal.pages.dashboard';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Auth::user();
        if ($user === null) {
            return ['characters' => []];
        }
        $characters = $user->characters()->get();
        $cards = [];
        foreach ($characters as $char) {
            $cards[] = $this->buildCharacterCard($char);
        }
        return ['characters' => $cards];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCharacterCard(object $char): array
    {
        $cid = (int) $char->character_id;

        $currentCorpId = (int) ($char->corporation_id ?? 0) ?: null;
        $currentAllyId = (int) ($char->alliance_id ?? 0) ?: null;

        // Entity names for current + historical affiliation.
        $entityIds = array_filter([$cid, $currentCorpId, $currentAllyId]);
        $names = DB::table('esi_entity_names')
            ->whereIn('entity_id', $entityIds)
            ->pluck('name', 'entity_id')
            ->all();

        // Corporation history, newest first. Each row: character was in
        // corp X from start_date to end_date (null = current). For every
        // corp, ask CorporationAllianceHistory which alliance that corp
        // was in at the start_date of the character's membership —
        // gives the "alliance at that time" timeline the operator
        // actually cares about.
        $corpHist = DB::table('character_corporation_history')
            ->where('character_id', $cid)
            ->where('is_deleted', 0)
            ->orderByDesc('start_date')
            ->select('corporation_id', 'start_date', 'end_date')
            ->get();

        $corpIds = $corpHist->pluck('corporation_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        if ($corpIds !== []) {
            $corpNames = DB::table('esi_entity_names')
                ->whereIn('entity_id', $corpIds)
                ->where('category', 'corporation')
                ->pluck('name', 'entity_id')
                ->all();
        } else {
            $corpNames = [];
        }

        // Alliance-at-time lookup. Reuse CorporationAllianceHistory.
        $timelineAlliances = [];
        foreach ($corpHist as $row) {
            $startTs = $row->start_date;
            $corpId = (int) $row->corporation_id;
            $allyRow = DB::table('corporation_alliance_history')
                ->where('corporation_id', $corpId)
                ->where('start_date', '<=', $startTs)
                ->where(function ($q) use ($startTs): void {
                    $q->whereNull('end_date')->orWhere('end_date', '>=', $startTs);
                })
                ->orderByDesc('start_date')
                ->first();
            $aid = $allyRow && $allyRow->alliance_id ? (int) $allyRow->alliance_id : null;
            $aname = null;
            if ($aid !== null) {
                $aname = DB::table('esi_entity_names')
                    ->where('entity_id', $aid)
                    ->where('category', 'alliance')
                    ->value('name');
            }
            $timelineAlliances[] = [
                'corp_id' => $corpId,
                'corp_name' => $corpNames[$corpId] ?? "Corp #{$corpId}",
                'start_date' => $startTs,
                'end_date' => $row->end_date,
                'alliance_id' => $aid,
                'alliance_name' => $aname,
            ];
        }

        // Distinct alliances chronologically (collapse repeated corp→same ally).
        $distinctAlliances = [];
        $prevAid = null;
        foreach (array_reverse($timelineAlliances) as $row) {
            if ($row['alliance_id'] === null) continue;
            if ($row['alliance_id'] === $prevAid) continue;
            $distinctAlliances[] = [
                'alliance_id' => $row['alliance_id'],
                'alliance_name' => $row['alliance_name'] ?? "#{$row['alliance_id']}",
                'first_seen' => $row['start_date'],
            ];
            $prevAid = $row['alliance_id'];
        }
        // Newest first for display.
        $distinctAlliances = array_reverse($distinctAlliances);

        // Kill stats from killmails — cheap counts.
        $kills = DB::table('killmail_attackers')
            ->where('character_id', $cid)
            ->count();
        $losses = DB::table('killmails')
            ->where('victim_character_id', $cid)
            ->count();

        // Top 3 hulls flown (attacker rows).
        $topHulls = DB::table('killmail_attackers AS ka')
            ->leftJoin('ref_item_types AS rit', 'rit.id', '=', 'ka.ship_type_id')
            ->where('ka.character_id', $cid)
            ->whereNotNull('ka.ship_type_id')
            ->selectRaw('ka.ship_type_id, rit.name, COUNT(*) AS n')
            ->groupBy('ka.ship_type_id', 'rit.name')
            ->orderByDesc('n')
            ->limit(3)
            ->get()
            ->map(fn ($r) => [
                'type_id' => (int) $r->ship_type_id,
                'name' => (string) ($r->name ?? "type {$r->ship_type_id}"),
                'n' => (int) $r->n,
            ])
            ->all();

        return [
            'character_id' => $cid,
            'character_name' => $names[$cid] ?? $char->character_name ?? "Pilot #{$cid}",
            'corporation_id' => $currentCorpId,
            'corporation_name' => $currentCorpId ? ($names[$currentCorpId] ?? null) : null,
            'alliance_id' => $currentAllyId,
            'alliance_name' => $currentAllyId ? ($names[$currentAllyId] ?? null) : null,
            'alliances_timeline' => $distinctAlliances,
            'corp_timeline' => $timelineAlliances,
            'kills' => $kills,
            'losses' => $losses,
            'top_hulls' => $topHulls,
        ];
    }
}

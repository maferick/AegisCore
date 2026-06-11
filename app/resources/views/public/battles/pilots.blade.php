@extends('public.layout', ['page_class' => 'battles-pilots'])

@section('title', 'Pilots — Battle in '.($theater->primarySystem?->name ?? '#'.$theater->primary_system_id))

@php
    use App\Domains\KillmailsBattleTheaters\Services\BattleTheaterSideResolver as S;

    // Side filter
    $sidePilots = $participants
        ->filter(fn ($p) => ($sides->sideByCharacterId[(int) $p->character_id] ?? 'C') === $side_key)
        ->sortBy(fn ($p) => -((float) $p->isk_lost))
        ->values();

    // Theater's full killmail set so pod kms (collapsed in kill_feed)
    // are included for pod-attach-to-ship logic in the row partial.
    $_kmIdsForLookup = \Illuminate\Support\Facades\DB::table('battle_theater_killmails')
        ->where('theater_id', $theater->id)
        ->pluck('killmail_id')
        ->all();

    $lossKmsByChar = [];
    $fbKmsByChar = [];
    if ($_kmIdsForLookup !== []) {
        \Illuminate\Support\Facades\DB::table('killmails as k')
            ->leftJoin('ref_item_types as t', 't.id', '=', 'k.victim_ship_type_id')
            ->leftJoin('ref_item_groups as ig', 'ig.id', '=', 't.group_id')
            ->whereIn('k.killmail_id', $_kmIdsForLookup)
            ->whereNotNull('k.victim_character_id')
            ->select(['k.killmail_id', 'k.victim_character_id', 'k.victim_ship_type_id',
                      'k.total_value', 'k.killed_at', 't.group_id', 'ig.category_id'])
            ->get()
            ->each(function ($r) use (&$lossKmsByChar, $ship_names): void {
                $cid = (int) $r->victim_character_id;
                $tid = (int) $r->victim_ship_type_id;
                $lossKmsByChar[$cid][] = [
                    'km' => (int) $r->killmail_id,
                    'ship' => (string) ($ship_names[$tid] ?? '#'.$tid),
                    'tid' => $tid,
                    'gid' => (int) ($r->group_id ?? 0),
                    'cat' => (int) ($r->category_id ?? 0),
                    'value' => (float) $r->total_value,
                    'at' => (string) $r->killed_at,
                ];
            });
        foreach ($lossKmsByChar as $cid => $rows) {
            usort($lossKmsByChar[$cid], fn ($a, $b) => strcmp($a['at'], $b['at']));
        }
        \Illuminate\Support\Facades\DB::table('killmail_attackers as a')
            ->join('killmails as k', 'k.killmail_id', '=', 'a.killmail_id')
            ->whereIn('a.killmail_id', $_kmIdsForLookup)
            ->where('a.is_final_blow', true)
            ->whereNotNull('a.character_id')
            ->select(['a.killmail_id', 'a.character_id', 'k.victim_ship_type_id', 'k.killed_at'])
            ->get()
            ->each(function ($r) use (&$fbKmsByChar, $ship_names): void {
                $cid = (int) $r->character_id;
                $tid = (int) $r->victim_ship_type_id;
                $fbKmsByChar[$cid][] = [
                    'km' => (int) $r->killmail_id,
                    'victim_ship' => (string) ($ship_names[$tid] ?? '#'.$tid),
                    'tid' => $tid,
                    'at' => (string) $r->killed_at,
                ];
            });
    }
    $deathEventsByChar = [];
    $_nonKillCats = [22, 87, 18];
    foreach ($lossKmsByChar as $cid => $events) {
        $shipEvents = []; $podEvents = [];
        foreach ($events as $e) {
            if ($e['gid'] === 29) $podEvents[] = $e; else $shipEvents[] = $e;
        }
        $out = [];
        foreach ($shipEvents as $e) {
            $out[] = [
                'ship' => ['tid' => $e['tid'], 'name' => $e['ship'], 'km' => $e['km'], 'value' => $e['value']],
                'pod' => null, 'at' => $e['at'],
                'is_real' => ! in_array($e['cat'], $_nonKillCats, true),
            ];
        }
        foreach ($podEvents as $pe) {
            $podTs = strtotime($pe['at']);
            $bestIdx = -1; $bestTs = 0;
            foreach ($out as $idx => $de) {
                if ($de['pod'] !== null || $de['ship'] === null) continue;
                $deTs = strtotime($de['at']);
                if ($deTs > $podTs) continue;
                if ($deTs > $bestTs) { $bestIdx = $idx; $bestTs = $deTs; }
            }
            if ($bestIdx === -1) {
                foreach ($out as $idx => $de) {
                    if ($de['pod'] === null && $de['ship'] !== null) { $bestIdx = $idx; break; }
                }
            }
            if ($bestIdx !== -1) {
                $out[$bestIdx]['pod'] = ['tid' => $pe['tid'], 'km' => $pe['km'], 'value' => $pe['value']];
            }
        }
        $deathEventsByChar[$cid] = $out;
    }
    $killmailUrl = fn (int $kmId): string => "/kills/{$kmId}";
    $allShipsOf = function (int $cid) use ($ships_by_character, $ship_names): array {
        $hulls = $ships_by_character[$cid] ?? [];
        if ($hulls === []) return [];
        arsort($hulls);
        $out = [];
        foreach ($hulls as $tid => $n) {
            $out[] = ['type_id' => (int) $tid, 'name' => $ship_names[(int) $tid] ?? '#'.$tid, 'count' => (int) $n];
        }
        return $out;
    };
    $roleBadge = fn (int $cid): string => '';
    $formatIsk = function (float $v): string {
        if ($v >= 1e12) return number_format($v / 1e12, 2) . 'T';
        if ($v >= 1e9)  return number_format($v / 1e9, 2)  . 'B';
        if ($v >= 1e6)  return number_format($v / 1e6, 2)  . 'M';
        if ($v >= 1e3)  return number_format($v / 1e3, 1)  . 'K';
        return number_format($v);
    };
    $sideLabels = ['A' => 'Side A', 'B' => 'Side B', 'C' => 'Third parties'];
    $sideColors = ['A' => '#4fd0d0', 'B' => '#ff3838', 'C' => '#7a7a82'];
    $sideKey = $side_key;
    $sideColor = $sideColors[$sideKey] ?? '#7a7a82';
    $sideLbl = $sideLabels[$sideKey] ?? '';
@endphp

@push('head')
    <style>
        /* Page-level styles. Pilot card / timeline classes mirror those
           in partials/battle-theater-body.blade.php so the row partial
           renders identically here. */
        body.aegis-public-bg { background: #050507; color: #e5e5e7; }
        .km-card { background: rgba(17,17,19,0.6); border: 1px solid #1a1a1e; border-radius: 6px; padding: 1rem 1.25rem; margin-bottom: 1rem; }
        .km-card h1 { font-size: 1.05rem; margin: 0 0 0.5rem 0; font-family: 'JetBrains Mono', monospace; color: #e5e5e7; }
        .km-card h1 .muted { color: #7a7a82; font-weight: 400; }
        .pilots-back { display: inline-block; margin-bottom: 0.75rem; color: #4fd0d0; text-decoration: none; font-size: 0.85rem; padding: 0.5rem 0; }
        .pilots-back:hover { color: #e5e5e7; }

        .bt-pilot-card {
            display: grid;
            grid-template-columns: minmax(180px, 230px) 1fr minmax(110px, 140px);
            gap: 0.75rem; align-items: center;
            padding: 0.55rem 0.7rem;
            border-bottom: 1px solid #1a1a1e;
            border-left: 3px solid transparent;
        }
        .bt-pilot-card:last-child { border-bottom: none; }
        .bt-pilot-card.died { border-left-color: #ff3838; background: rgba(255,56,56,0.035); }
        .bt-pilot-card.alive { border-left-color: rgba(74,222,128,0.35); }
        @media (max-width: 700px) {
            .bt-pilot-card { grid-template-columns: 1fr; gap: 0.4rem; }
        }
        .bt-pilot-id { display: flex; align-items: center; gap: 0.6rem; min-width: 0; }
        .bt-pilot-portrait { width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0; }
        .bt-pilot-id-text { min-width: 0; flex: 1; }
        .bt-pilot-name { font-size: 0.84rem; font-weight: 700; color: #ffffff; display: flex; flex-wrap: wrap; gap: 4px; align-items: center; line-height: 1.25; }
        .bt-pilot-affil { display: flex; align-items: center; gap: 5px; margin-top: 3px; font-size: 0.72rem; color: #9a9aa2; }
        .bt-pilot-affil img { width: 16px; height: 16px; border-radius: 2px; flex-shrink: 0; background: #1a1a1e; }
        .bt-pilot-affil .alli-name { color: #b8b8c0; font-weight: 500; }
        .bt-pilot-affil .corp-name { color: #7a7a82; }
        .bt-died-badge, .bt-survived-badge, .bt-fb-badge { display: inline-flex; align-items: center; padding: 0.1rem 0.4rem; border-radius: 3px; font-size: 0.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; }
        .bt-died-badge { background: rgba(255,56,56,0.18); color: #ff7878; }
        .bt-survived-badge { background: rgba(74,222,128,0.12); color: #4ade80; }
        .bt-fb-badge { background: rgba(229,169,0,0.15); color: #e5a900; }

        .bt-pilot-timeline { min-width: 0; }
        .bt-pilot-survived-msg { font-size: 0.72rem; color: #7a7a82; font-style: italic; }
        .bt-timeline { list-style: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 0.4rem 0.55rem; align-items: center; }
        .bt-timeline-step { display: flex; align-items: center; gap: 0.35rem; }
        .bt-timeline-step + .bt-timeline-step::before { content: '→'; color: #ff3838; font-size: 0.85rem; margin-right: 0.25rem; font-weight: 700; }
        .bt-timeline-node { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; flex-shrink: 0; border-radius: 50%; background: rgba(255,56,56,0.18); color: #ff7878; font-size: 0.65rem; font-weight: 700; font-family: 'JetBrains Mono', monospace; }
        .bt-timeline-body { display: inline-flex; align-items: center; gap: 0.3rem; flex-wrap: wrap; }
        .bt-timeline-link { display: inline-flex; align-items: center; gap: 4px; padding: 2px 6px 2px 3px; border-radius: 3px; background: rgba(255,56,56,0.10); border: 1px solid rgba(255,56,56,0.25); color: #ffb0b0; text-decoration: none; font-size: 0.72rem; line-height: 1; }
        .bt-timeline-link:hover { background: rgba(255,56,56,0.20); color: #ffffff; }
        .bt-timeline-link img { width: 18px; height: 18px; border-radius: 2px; background: #1a1a1e; }
        .bt-timeline-link.bt-timeline-pod { background: rgba(255,56,56,0.06); border-color: rgba(255,56,56,0.18); color: #ff9999; }
        .bt-timeline-name { font-weight: 500; }
        .bt-timeline-then { color: #ff3838; font-size: 0.75rem; font-weight: 700; }

        .bt-shipboxes { display: flex; flex-wrap: wrap; gap: 5px; align-items: center; }
        .bt-shipbox { display: inline-flex; align-items: center; gap: 4px; padding: 3px 7px 3px 4px; border-radius: 3px; font-size: 0.72rem; line-height: 1; text-decoration: none; }
        .bt-shipbox img { width: 18px; height: 18px; border-radius: 2px; background: #1a1a1e; flex-shrink: 0; }
        .bt-shipbox-podicon { opacity: 0.85; }
        .bt-shipbox-name { font-weight: 500; }
        .bt-shipbox-count { color: rgba(255,255,255,0.65); font-size: 0.65rem; }
        .bt-shipbox-then { color: #ff3838; font-weight: 700; font-size: 0.75rem; }
        .bt-shipbox.lost { background: rgba(255,56,56,0.10); border: 1px solid rgba(255,56,56,0.30); color: #ffb0b0; }
        .bt-shipbox-link, .bt-shipbox-podlink { display: inline-flex; align-items: center; gap: 4px; text-decoration: none; color: inherit; border-radius: 2px; padding: 1px 2px; margin: -1px -2px; }
        .bt-shipbox-link:hover, .bt-shipbox-podlink:hover { background: rgba(255,255,255,0.08); color: #ffffff; }
        .bt-shipbox.alive { background: rgba(74,222,128,0.10); border: 1px solid rgba(74,222,128,0.30); color: #b8e8c8; }
        .bt-roadblock-list { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; margin-top: 5px; padding: 4px 6px; border-radius: 3px; background: rgba(255,56,56,0.04); border: 1px dashed rgba(255,56,56,0.18); }
        .bt-roadblock-label { font-size: 0.6rem; color: #ff9999; text-transform: uppercase; letter-spacing: 0.08em; margin-right: 4px; }
        .bt-roadblock-item { display: inline-flex; align-items: center; gap: 3px; padding: 1px 6px 1px 2px; border-radius: 3px; background: rgba(255,56,56,0.08); border: 1px solid rgba(255,56,56,0.18); text-decoration: none; font-size: 0.7rem; line-height: 1; color: #ff9999; }
        .bt-roadblock-item img { width: 16px; height: 16px; border-radius: 2px; background: #1a1a1e; }
        .bt-roadblock-count { color: #ffb0b0; font-weight: 700; }
        .bt-roadblock-name { color: #ff9999; }
        .bt-pilot-fblist { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; margin-top: 6px; padding-top: 5px; border-top: 1px dashed #2a2a30; }
        .bt-fblist-label { font-size: 0.65rem; color: #7a7a82; text-transform: uppercase; letter-spacing: 0.08em; margin-right: 4px; }
        .bt-fblink { display: inline-flex; padding: 2px; border-radius: 3px; background: rgba(74,222,128,0.10); border: 1px solid rgba(74,222,128,0.25); }
        .bt-fblink:hover { background: rgba(74,222,128,0.20); }
        .bt-fblink img { width: 18px; height: 18px; border-radius: 2px; background: #1a1a1e; display: block; }

        .bt-pilot-outcome { text-align: right; font-family: 'JetBrains Mono', monospace; font-size: 0.78rem; line-height: 1.4; }
        .bt-outcome-kills { color: #4ade80; font-weight: 700; }
        .bt-outcome-dmg { color: #b8b8c0; }
        .bt-outcome-loss { color: #ff3838; font-weight: 700; }
        .bt-outcome-suffix { font-size: 0.62rem; color: #7a7a82; font-weight: 400; }

        @media (pointer: coarse) {
            .bt-timeline-link, .bt-fblink { padding: 6px 8px 6px 5px; }
            .bt-timeline-link img, .bt-fblink img { width: 22px; height: 22px; }
            .bt-timeline-node { width: 22px; height: 22px; font-size: 0.72rem; }
        }
    </style>
@endpush

@section('content')
<a href="/battles/{{ $theater->public_slug ?? $theater->id }}" class="pilots-back">← Back to battle report</a>
<div class="km-card" style="border-left: 3px solid {{ $sideColor }};">
    <h1>
        Pilots — {{ $sideLbl }}
        <span class="muted">· {{ number_format($sidePilots->count()) }}</span>
    </h1>
    @if ($sidePilots->isEmpty())
        <div style="color:#7a7a82;font-style:italic;">No pilots on this side.</div>
    @else
        @foreach ($sidePilots as $p)
            @include('partials.battle-pilot-row')
        @endforeach
    @endif
</div>
@endsection

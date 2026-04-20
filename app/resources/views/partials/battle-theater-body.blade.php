{{-- Namespace imports need to live on the included file — ``@use``
     expands to a PHP ``use`` in the compiled view's function scope,
     and that scope is per-file, so importing in the wrapper view
     doesn't reach down into this partial. --}}
@use('App\Filament\Portal\Pages\Battles')
@use('App\Domains\KillmailsBattleTheaters\Services\BattleTheaterSideResolver', 'S')

@php
    // Defensive default — public callers pass hide_bloc_names=true,
    // authed portal callers pass it false, but older tests that
    // render the partial directly may not set it at all.
    $hide_bloc_names = $hide_bloc_names ?? false;
    $overrides = $overrides ?? collect();

    // Signed-in users see the "move this alliance" controls; anon
    // viewers on /battles get a read-only report.
    $can_override = auth()->check();

    // Alliance-level override lookup for the "(manual)" badge next
    // to overridden rows.
    $override_by_alliance = [];
    foreach ($overrides as $o) {
        if ($o->entity_type === 'alliance') {
            $override_by_alliance[(int) $o->entity_id] = $o->side;
        }
    }
@endphp

@php
    /** @var \App\Domains\KillmailsBattleTheaters\Models\BattleTheater $theater */
    /** @var \App\Domains\KillmailsBattleTheaters\Services\BattleTheaterSideResolution $sides */
    /** @var \Illuminate\Support\Collection $blocs */
    /** @var \Illuminate\Support\Collection $names */
    /** @var \Illuminate\Support\Collection $participants */
    /** @var \Illuminate\Support\Collection $systems */
    /** @var array $side_totals */
    /** @var \Illuminate\Support\Collection $alliance_rows */
    /** @var \App\Domains\UsersCharacters\Models\ViewerContext|null $viewer */
    /** @var array $ships_by_character */
    /** @var \Illuminate\Support\Collection $ship_names */
    /** @var array $kill_feed */
    /** @var array $composition */
    /** @var array $most_valuable_kills */
    /** @var array $top_damage */
    /** @var array $roster_by_side */
    /** @var array $flagship_logos */
    /** @var array $header_stats */

    $formatIsk = fn (float $v): string =>
        $v >= 1e12 ? number_format($v / 1e12, 2).' T'
        : ($v >= 1e9  ? number_format($v / 1e9, 2).' B'
        : ($v >= 1e6  ? number_format($v / 1e6, 2).' M'
        : ($v >= 1e3  ? number_format($v / 1e3, 1).' K'
        : number_format($v, 0))));

    // Spec 6 inline role badges. Rendered next to pilot names on
    // rosters + top-damage cards. Only FC / logi / mainline_dps
    // assignments fire; every other pilot gets no badge (silent
    // per Spec 5 epistemic stance).
    $roleByChar = $role_by_character ?? [];
    $roleByKmChar = $role_by_killmail_character ?? [];
    $roleLabel = fn (string $r): string => match ($r) {
        'fc' => 'FC',
        'logi' => 'Logi',
        'mainline_dps' => 'DPS',
        'tackle' => 'Tackle',
        'bomber' => 'Bomber',
        'command' => 'Cmd',
        'other' => '',
        default => strtoupper($r),
    };
    $roleBadgeStyle = fn (string $r): string => match ($r) {
        'fc' => 'background:rgba(202,138,4,0.25);color:#fde047;border:1px solid rgba(250,204,21,0.35);box-shadow:0 0 4px rgba(250,204,21,0.35);',
        'logi' => 'background:rgba(5,150,105,0.2);color:#6ee7b7;border:1px solid rgba(16,185,129,0.3);',
        'mainline_dps' => 'background:rgba(30,64,175,0.2);color:#93c5fd;border:1px solid rgba(59,130,246,0.3);',
        'tackle' => 'background:rgba(8,145,178,0.25);color:#67e8f9;border:1px solid rgba(14,165,233,0.35);',
        'bomber' => 'background:rgba(234,88,12,0.25);color:#fdba74;border:1px solid rgba(249,115,22,0.35);',
        'command' => 'background:rgba(168,85,247,0.25);color:#f0abfc;border:1px solid rgba(192,132,252,0.35);',
        default => 'background:rgba(71,85,105,0.25);color:#cbd5e1;border:1px solid rgba(100,116,139,0.3);',
    };
    $roleBadge = function (int $cid) use ($roleByChar, $roleLabel, $roleBadgeStyle): string {
        $role = $roleByChar[$cid] ?? null;
        if ($role === null) return '';
        return '<span style="display:inline-block;padding:1px 5px;margin-left:5px;font-size:0.6rem;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;line-height:1;border-radius:8px;vertical-align:middle;'
            . $roleBadgeStyle($role) . '">' . $roleLabel($role) . '</span>';
    };
    // Per-killmail badge: uses killmail_pilot_role so a reshipping pilot
    // shows the role they had on THIS killmail rather than the battle
    // aggregate. Falls back to no badge when the pilot's ship on this
    // killmail has no role mapping ('other' or unmapped hull).
    $roleBadgeForKm = function (int $kmId, int $cid) use ($roleByKmChar, $roleLabel, $roleBadgeStyle): string {
        $role = $roleByKmChar[$kmId][$cid] ?? null;
        if ($role === null || $role === 'other') return '';
        $label = $roleLabel($role);
        if ($label === '') return '';
        return '<span style="display:inline-block;padding:1px 5px;margin-left:5px;font-size:0.6rem;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;line-height:1;border-radius:8px;vertical-align:middle;'
            . $roleBadgeStyle($role) . '">' . $label . '</span>';
    };

    $blocA = $sides->sideABlocId ? ($blocs[$sides->sideABlocId]->display_name ?? null) : null;
    $blocB = $sides->sideBBlocId ? ($blocs[$sides->sideBBlocId]->display_name ?? null) : null;
    $flagA = $flagship_logos[S::SIDE_A] ?? null;
    $flagB = $flagship_logos[S::SIDE_B] ?? null;

    // Banner headline = biggest alliance on the side. Bloc subtitle.
    $sideAHeadline = $flagA['alliance_name'] ?? $blocA ?? 'Side A';
    $sideBHeadline = $flagB['alliance_name'] ?? $blocB ?? 'No opposing side';
    $flagC = $flagship_logos[S::SIDE_C] ?? null;
    $sideCHeadline = $flagC['alliance_name'] ?? 'Third parties';
    $hasSideC = ! empty($roster_by_side[S::SIDE_C] ?? null) && ($roster_by_side[S::SIDE_C]->count() > 0);

    $primaryShipOf = function (int $characterId) use ($ships_by_character, $ship_names): array {
        $rows = $ships_by_character[$characterId] ?? [];
        if ($rows === []) return ['type_id' => null, 'name' => '—'];
        arsort($rows);
        $tid = (int) array_key_first($rows);
        return ['type_id' => $tid, 'name' => $ship_names[$tid] ?? '#'.$tid];
    };
    // Every hull the pilot appeared in during the battle, highest-count
    // first. Multi-ship pilots (Richard in Monitor + Claymore, pilots
    // who got podded and reshipped, etc.) need to show the full list
    // so the reader sees the whole picture, not just the mode hull.
    $allShipsOf = function (int $characterId) use ($ships_by_character, $ship_names): array {
        $rows = $ships_by_character[$characterId] ?? [];
        if ($rows === []) return [];
        arsort($rows);
        $out = [];
        foreach ($rows as $tid => $n) {
            $out[] = [
                'type_id' => (int) $tid,
                'name' => $ship_names[(int) $tid] ?? '#'.$tid,
                'count' => (int) $n,
            ];
        }
        return $out;
    };

    $tA = $side_totals[S::SIDE_A];
    $tB = $side_totals[S::SIDE_B];
    $tC = $side_totals[S::SIDE_C];
    $effA = ($tA['isk_killed'] + $tA['isk_lost']) > 0
        ? round($tA['isk_killed'] / ($tA['isk_killed'] + $tA['isk_lost']) * 100, 1)
        : 50.0;
    $effB = ($tB['isk_killed'] + $tB['isk_lost']) > 0
        ? round($tB['isk_killed'] / ($tB['isk_killed'] + $tB['isk_lost']) * 100, 1)
        : 50.0;
    $effC = ($tC['isk_killed'] + $tC['isk_lost']) > 0
        ? round($tC['isk_killed'] / ($tC['isk_killed'] + $tC['isk_lost']) * 100, 1)
        : null;

    // Three-way destroyed-share split bar. Widths proportional to
    // each side's isk_killed (FB attribution per spec § 2.1).
    $totalDestroyed = (float) ($tA['isk_killed'] + $tB['isk_killed'] + $tC['isk_killed']);
    $barA = $totalDestroyed > 0 ? round($tA['isk_killed'] / $totalDestroyed * 100, 2) : 50.0;
    $barB = $totalDestroyed > 0 ? round($tB['isk_killed'] / $totalDestroyed * 100, 2) : 50.0;
    $barC = $totalDestroyed > 0 ? round($tC['isk_killed'] / $totalDestroyed * 100, 2) : 0.0;

    $sysSec = (float) ($theater->primarySystem?->security_status ?? 0.0);
    $sysSecColor = $sysSec >= 0.5 ? '#4ade80' : ($sysSec >= 0.0 ? '#e5a900' : '#ff3838');

    $dur = $theater->durationSeconds();
    $durFmt = sprintf('%02d:%02d:%02d', intdiv($dur, 3600), intdiv($dur % 3600, 60), $dur % 60);

    // Side → tone colour used in borders / accents. Matches the
    // killmail page palette — cyan/red/muted, not tailwind.
    $sideToneColor = fn (string $s): string => match ($s) {
        'A' => '#4fd0d0',   // cyan — Side A
        'B' => '#ff3838',   // red  — Side B
        default => '#7a7a82',
    };

    // N-side view model. Every per-side section iterates $sideList
    // (auto-fit grid, compact 2×4 stats, share-bar segment, roster
    // card, etc.). Colour palette is additive: A→red/cyan stays, C
    // gains amber so third-party reads as a team not as grey filler.
    // Ordered by isk_killed desc so the biggest contributor lands
    // left-most on wide screens.
    // A side is "present" iff it has any pilot on it. Extra guard on
    // Side B: if the resolver never found a flagship alliance (no
    // $flagB, headline falls back to the "No opposing side" sentinel)
    // then anything still tagged B is structural leftover.
    $sideIsPresent = fn (array $t): bool =>
        (int) ($t['pilots'] ?? 0) > 0
        || (float) ($t['isk_killed'] ?? 0) > 0
        || (float) ($t['isk_lost'] ?? 0) > 0;
    $sideBValid = $sideBHeadline !== 'No opposing side' && $sideIsPresent($tB);

    // When the viewer-aware resolver kills Side B (neither faction in
    // the fight belongs to the viewer's bloc, so everybody on the
    // non-A side gets bucketed into third parties), promote the C
    // cluster into the B slot. The battle was two-sided in reality
    // (Fraternity vs TIME CRIT style), and rendering it as "Side A +
    // third parties" buries the fact that C was the actual antagonist.
    $promoteCtoB = ! $sideBValid && $sideIsPresent($tC);

    $sideList = [];
    if ($sideIsPresent($tA)) {
        $sideList[] = [
            'key' => S::SIDE_A, 'tone' => 'a', 'color' => '#4fd0d0',
            'headline' => $sideAHeadline, 'sub' => $blocA, 'logo' => $flagA,
            'totals' => $tA, 'eff' => $effA, 'bar' => $barA,
        ];
    }
    if ($sideBValid) {
        $sideList[] = [
            'key' => S::SIDE_B, 'tone' => 'b', 'color' => '#ff3838',
            'headline' => $sideBHeadline, 'sub' => $blocB, 'logo' => $flagB,
            'totals' => $tB, 'eff' => $effB, 'bar' => $barB,
        ];
    } elseif ($promoteCtoB) {
        // C promoted: keep SIDE_C as the internal key (so per-side
        // lookups into $roster_by_side / $most_valuable_kills /
        // $composition / $top_damage still resolve against the C
        // bucket where the data actually lives), but paint it with
        // the B tone/colour and label "Side B" so the layout reads
        // as a proper two-sided fight.
        $sideList[] = [
            'key' => S::SIDE_C, 'tone' => 'b', 'color' => '#ff3838',
            'headline' => $sideCHeadline, 'sub' => null, 'logo' => $flagC,
            'totals' => $tC, 'eff' => $effC, 'bar' => $barC,
            'label_override' => 'Side B',
        ];
    }
    if (! $promoteCtoB && ($hasSideC || $sideIsPresent($tC))) {
        $sideList[] = [
            'key' => S::SIDE_C, 'tone' => 'c', 'color' => '#fbbf24',
            'headline' => $sideCHeadline, 'sub' => null, 'logo' => $flagC,
            'totals' => $tC, 'eff' => $effC, 'bar' => $barC,
        ];
    }
    // Keep A / B / C in declared order — viewers expect the label
    // "Side A" to always mean the same column, not shift around
    // based on who destroyed more ISK this fight.
@endphp

<style>
    /* Re-uses the km-* language from the killmail detail page, with
       a few bt-* extensions for side-aware pieces (split bar, side
       headers, flagship banner) that don't exist on a single kill. */
    .km-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
    .km-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; }
    @media (max-width: 900px) {
        .km-grid, .km-grid-3 { grid-template-columns: 1fr; }
    }
    .km-card { background: rgba(17,17,19,0.6); border: 1px solid #26262b; border-radius: 8px; padding: 1.25rem; }
    .km-card h3 { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.12em; color: #7a7a82; margin-bottom: 0.75rem; font-family: 'JetBrains Mono', monospace; }
    .km-card h3 .muted { color: #3a3a42; font-weight: 400; margin-left: 0.25rem; }

    .km-victim-header { display: flex; gap: 1.25rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.5rem; }
    .km-victim-name { font-size: 1.4rem; font-weight: 700; color: #e5e5e7; }
    .km-victim-meta { font-size: 0.8rem; color: #7a7a82; }
    .km-victim-meta img { width: 18px; height: 18px; border-radius: 3px; vertical-align: middle; margin-right: 3px; }

    .km-stat { display: flex; justify-content: space-between; padding: 0.3rem 0; border-bottom: 1px solid #1a1a1e; }
    .km-stat:last-child { border-bottom: none; }
    .km-stat-label { font-size: 0.78rem; color: #7a7a82; }
    .km-stat-value { font-size: 0.78rem; font-family: 'JetBrains Mono', monospace; color: #e5e5e7; }
    .km-stat-value.isk { color: #e5a900; }
    .km-stat-value.loss { color: #ff3838; font-weight: 700; }
    .km-stat-value.kill { color: #4ade80; font-weight: 700; }
    .km-stat-value.total { color: #ff3838; font-weight: 700; font-size: 0.9rem; }

    .km-item-row { display: flex; align-items: center; gap: 0.5rem; padding: 0.35rem 0; border-bottom: 1px solid #1a1a1e; }
    .km-item-row:last-child { border-bottom: none; }
    .km-item-icon { width: 28px; height: 28px; border-radius: 3px; flex-shrink: 0; }
    .km-item-name { flex: 1; font-size: 0.78rem; color: #e5e5e7; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .km-item-qty { font-family: 'JetBrains Mono', monospace; font-size: 0.72rem; color: #7a7a82; }
    .km-item-value { font-family: 'JetBrains Mono', monospace; font-size: 0.72rem; color: #e5a900; text-align: right; min-width: 70px; }

    .km-attacker { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0; border-bottom: 1px solid #1a1a1e; }
    .km-attacker:last-child { border-bottom: none; }
    .km-attacker-portrait { width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0; }
    .km-attacker-info { flex: 1; min-width: 0; }
    .km-attacker-name { font-size: 0.82rem; font-weight: 600; color: #e5e5e7; }
    .km-attacker-corp { font-size: 0.72rem; color: #7a7a82; }
    .km-attacker-ship { display: flex; align-items: center; gap: 4px; font-size: 0.72rem; color: #7a7a82; }
    .km-attacker-damage { font-family: 'JetBrains Mono', monospace; font-size: 0.75rem; color: #e5e5e7; text-align: right; }

    .km-final-blow { border-left: 2px solid #ff3838; padding-left: 0.5rem; }

    .km-badge { display: inline-block; padding: 0.1rem 0.45rem; border-radius: 3px; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.08em; font-family: 'JetBrains Mono', monospace; }
    .km-badge-red { background: rgba(255,56,56,0.15); color: #ff3838; }
    .km-badge-green { background: rgba(74,222,128,0.15); color: #4ade80; }
    .km-badge-cyan { background: rgba(79,208,208,0.12); color: #4fd0d0; }
    .km-badge-muted { background: rgba(122,122,130,0.15); color: #7a7a82; }

    /* Battle-theater extensions ---------------------------------- */
    .bt-vs-row { display: grid; grid-template-columns: 1fr auto 1fr; gap: 1rem; align-items: center; }
    @media (max-width: 700px) { .bt-vs-row { grid-template-columns: 1fr; text-align: left; } }
    .bt-side-a { color: #4fd0d0; }
    .bt-side-b { color: #ff3838; }
    .bt-side-c { color: #7a7a82; }
    .bt-side-head { display: flex; align-items: center; gap: 1rem; min-width: 0; }
    .bt-side-head.flip { flex-direction: row-reverse; text-align: right; }
    .bt-side-logo { width: 72px; height: 72px; border-radius: 8px; flex-shrink: 0; border: 2px solid rgba(79,208,208,0.25); }
    .bt-side-logo.right { border-color: rgba(255,56,56,0.3); }
    .bt-side-label { font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.15em; color: #7a7a82; font-family: 'JetBrains Mono', monospace; }
    .bt-side-name { font-size: 1.15rem; font-weight: 700; line-height: 1.2; }
    .bt-side-sub { font-size: 0.72rem; color: #7a7a82; }
    .bt-vs-center { text-align: center; }
    .bt-vs-label { font-size: 0.8rem; font-weight: 900; letter-spacing: 0.4em; color: #3a3a42; }
    .bt-eff-row { display: flex; justify-content: center; gap: 1rem; margin-top: 0.5rem; align-items: baseline; }
    .bt-eff-val { font-family: 'JetBrains Mono', monospace; font-weight: 700; font-size: 1.4rem; }
    .bt-eff-sep { color: #3a3a42; }
    .bt-bar { height: 8px; border-radius: 4px; overflow: hidden; display: flex; background: #1a1a1e; margin-top: 1rem; }
    .bt-bar-a { background: #4fd0d0; }
    .bt-bar-b { background: #ff3838; }
    .bt-bar-c { background: #7a7a82; }
    .bt-bar-legend { display: flex; justify-content: space-between; margin-top: 0.35rem; font-family: 'JetBrains Mono', monospace; font-size: 0.65rem; color: #7a7a82; }
    .bt-bar-legend .a { color: #4fd0d0; }
    .bt-bar-legend .b { color: #ff3838; }
    .bt-bar-legend .c { color: #7a7a82; }

    .bt-section-head {
        display: flex; justify-content: space-between; align-items: baseline;
        margin-bottom: 0.75rem;
    }
    .bt-section-head .sub { font-size: 0.7rem; color: #7a7a82; font-family: 'JetBrains Mono', monospace; }

    .bt-head-ribbon {
        display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: baseline;
        font-family: 'JetBrains Mono', monospace; font-size: 0.75rem; color: #7a7a82;
        margin-bottom: 1rem;
    }
    .bt-head-ribbon .sep { color: #3a3a42; }
    .bt-head-ribbon .sys { color: #e5e5e7; font-weight: 700; font-size: 0.85rem; }

    .bt-metric-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-top: 0.5rem; }
    @media (min-width: 900px) { .bt-metric-grid { grid-template-columns: repeat(6, 1fr); } }
    .bt-metric-label { font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.12em; color: #7a7a82; font-family: 'JetBrains Mono', monospace; }
    .bt-metric-value { font-family: 'JetBrains Mono', monospace; font-size: 1.05rem; font-weight: 700; color: #e5e5e7; margin-top: 0.15rem; }
    .bt-metric-value.loss { color: #ff3838; }
    .bt-metric-value.kill { color: #4ade80; }
    .bt-metric-value.isk { color: #e5a900; }

    .bt-comp-bar { flex: 1; height: 6px; border-radius: 3px; background: #1a1a1e; overflow: hidden; }
    .bt-comp-bar > div { height: 100%; }
    .bt-comp-bar.a > div { background: #4fd0d0; }
    .bt-comp-bar.b > div { background: #ff3838; }
    .bt-comp-bar.c > div { background: #7a7a82; }

    .bt-kill-time { font-family: 'JetBrains Mono', monospace; font-size: 0.7rem; color: #7a7a82; width: 70px; flex-shrink: 0; }
    .bt-kill-a { border-left: 3px solid rgba(79,208,208,0.6); padding-left: 0.6rem; }
    .bt-kill-b { border-left: 3px solid rgba(255,56,56,0.6); padding-left: 0.6rem; }
    .bt-kill-c { border-left: 3px solid rgba(122,122,130,0.4); padding-left: 0.6rem; }
    .bt-kill-high { background: rgba(229,169,0,0.07); }

    .bt-mvk-ship { width: 56px; height: 56px; border-radius: 4px; flex-shrink: 0; border: 1px solid rgba(79,208,208,0.25); }
    .bt-mvk-ship.b { border-color: rgba(255,56,56,0.3); }
    .bt-mvk-ship.c { border-color: rgba(122,122,130,0.35); }

    .bt-pilot-group-head {
        display: flex; align-items: center; gap: 0.5rem;
        padding-bottom: 0.5rem; margin-bottom: 0.5rem;
        border-bottom: 1px solid #26262b;
        font-family: 'JetBrains Mono', monospace; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.12em;
    }
    .bt-pilot-group-head.a { color: #4fd0d0; }
    .bt-pilot-group-head.b { color: #ff3838; }
    .bt-pilot-group-head.c { color: #7a7a82; }

    .bt-killfeed > summary {
        list-style: none; cursor: pointer;
        display: flex; align-items: center; gap: 0.75rem;
        padding: 0.1rem 0;
    }
    .bt-killfeed > summary::-webkit-details-marker { display: none; }
    .bt-killfeed > summary::before {
        content: '▸'; color: #7a7a82; font-size: 0.8rem; transition: transform 0.15s ease;
    }
    .bt-killfeed[open] > summary::before { transform: rotate(90deg); }
    .bt-killfeed-hint { font-size: 0.7rem; color: #7a7a82; font-style: italic; }
    .bt-killfeed[open] .bt-killfeed-hint { display: none; }

    .bt-pilot-link { display: block; text-decoration: none; color: inherit; }
    .bt-pilot-clickable { cursor: pointer; transition: background 0.1s ease; }
    .bt-pilot-link:hover .bt-pilot-clickable { background: rgba(255,56,56,0.06); }
    .bt-pilot-link:hover .km-attacker-name { color: #ff8080; }
    .bt-pilot-group-count { margin-left: auto; color: #7a7a82; font-weight: 400; letter-spacing: 0; text-transform: none; }

    /* N-side generic layout. Replaces km-grid-3 for per-side sections —
       wraps automatically when 2 sides, expands to 3 / 5 columns when
       more sides exist or the screen is wide enough. --side-color is
       passed through inline style="--side-color: …". */
    .side-columns { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1rem; align-items: stretch; margin-bottom: 1.5rem; }
    .side-card {
        background: rgba(17,17,19,0.6);
        border: 1px solid #26262b;
        border-left: 2px solid var(--side-color, #7a7a82);
        border-radius: 8px;
        padding: 1rem 1.1rem;
    }
    .side-card h3 {
        font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.12em;
        color: var(--side-color, #7a7a82); margin: 0 0 0.6rem;
        font-family: 'JetBrains Mono', monospace; font-weight: 700;
    }
    .side-card h3 .muted { color: #3a3a42; font-weight: 400; margin-left: 0.25rem; }
    .side-card h3 .headline { color: #e5e5e7; font-weight: 600; margin-left: 0.25rem; text-transform: none; letter-spacing: 0; font-size: 0.78rem; }

    /* Compact 2×4 stats tile inside each side-card. Tabular nums so
       columns align across cards on wide screens. */
    .side-stats { display: grid; grid-template-columns: 1fr 1fr; column-gap: 1rem; row-gap: 0.15rem; font-variant-numeric: tabular-nums; }
    .side-stats .cell { display: flex; align-items: baseline; justify-content: space-between; padding: 0.25rem 0; border-bottom: 1px solid #1a1a1e; }
    .side-stats .cell:nth-last-child(-n+2) { border-bottom: none; }
    .side-stats .label { font-size: 0.6rem; letter-spacing: 0.08em; text-transform: uppercase; color: rgba(229,229,231,0.45); font-family: 'JetBrains Mono', monospace; }
    .side-stats .value { font-size: 0.85rem; color: rgba(229,229,231,0.92); font-family: 'JetBrains Mono', monospace; text-align: right; }
    .side-stats .value.kill { color: #4ade80; font-weight: 700; }
    .side-stats .value.loss { color: #ff3838; font-weight: 700; }
    .side-stats .value.accent { color: var(--side-color, #e5a900); font-weight: 700; }

    /* N-segment share-of-destruction bar (generalised from 3-way
       teal/red/muted to any N using --side-color from the inline
       style on each segment). */
    .share-bar { height: 8px; border-radius: 4px; overflow: hidden; display: flex; background: #1a1a1e; margin-top: 1rem; }
    .share-bar > span { display: block; height: 100%; background: var(--side-color, #7a7a82); }
    .share-legend { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 0.35rem; font-family: 'JetBrains Mono', monospace; font-size: 0.65rem; }
    .share-legend > span { color: var(--side-color, #7a7a82); }
</style>

{{-- ================================================================
     HEADER — system, meta ribbon, top-line metric strip
     ================================================================ --}}
<div class="km-victim-header">
    <div>
        <div class="km-victim-name">
            <span style="color: {{ $sysSecColor }};">{{ number_format($sysSec, 1) }}</span>
            {{ $theater->primarySystem?->name ?? '#'.$theater->primary_system_id }}
        </div>
        <div class="bt-head-ribbon" style="margin-top: 0.35rem; margin-bottom: 0;">
            <span>{{ $theater->region?->name ?? '—' }}</span>
            <span class="sep">·</span>
            <span>{{ $theater->start_time?->format('M d, Y H:i') }} → {{ $theater->end_time?->format('H:i') }} UTC</span>
            <span class="sep">·</span>
            <span>{{ $durFmt }}</span>
            @if ($theater->locked_at)
                <span class="km-badge km-badge-muted" style="margin-left: 0.5rem;">Locked</span>
            @else
                <span class="km-badge km-badge-green" style="margin-left: 0.5rem;">Live</span>
            @endif
        </div>
    </div>
</div>

<div class="km-card" style="margin-bottom: 1.5rem;">
    <div class="bt-metric-grid">
        <div>
            <div class="bt-metric-label">ISK destroyed</div>
            <div class="bt-metric-value loss">
                {{ $formatIsk((float) ($reconciled_total_isk_lost ?? $theater->total_isk_lost)) }}
                @if (! empty($zkill_total_isk_lost) && (float) $zkill_total_isk_lost > 0)
                    <span style="font-size:0.7em; color:#9ca3af; font-weight:500;" title="zKillboard's price source; differs from ours when faction / deadspace / capital modules aren't in our feed">
                        ({{ $formatIsk((float) $zkill_total_isk_lost) }} zKill)
                    </span>
                @endif
            </div>
        </div>
        <div>
            <div class="bt-metric-label">Ships lost</div>
            <div class="bt-metric-value">{{ number_format($reconciled_total_kills ?? (int) $theater->total_kills) }}</div>
        </div>
        <div>
            <div class="bt-metric-label">Damage dealt</div>
            <div class="bt-metric-value">{{ number_format($header_stats['damage']) }}</div>
        </div>
        <div>
            <div class="bt-metric-label">Pilots</div>
            <div class="bt-metric-value">{{ number_format($theater->participant_count) }}</div>
        </div>
        <div>
            <div class="bt-metric-label">Corporations</div>
            <div class="bt-metric-value">{{ number_format($header_stats['corps']) }}</div>
        </div>
        <div>
            <div class="bt-metric-label">Alliances</div>
            <div class="bt-metric-value">{{ number_format($header_stats['alliances']) }}</div>
        </div>
    </div>
</div>

{{-- ================================================================
     VS BANNER — big alliance logos, efficiency split
     ================================================================ --}}
<div class="km-card" style="margin-bottom: 1.5rem;">
    <div class="bt-vs-row">
        <div class="bt-side-head">
            @if ($flagA)
                <img src="https://images.evetech.net/alliances/{{ $flagA['alliance_id'] }}/logo?size=128"
                     referrerpolicy="no-referrer" class="bt-side-logo" alt="">
            @else
                <div class="bt-side-logo" style="display:flex;align-items:center;justify-content:center;background:rgba(79,208,208,0.08);color:#4fd0d0;font-weight:900;font-size:1.8rem;">A</div>
            @endif
            <div style="min-width:0;">
                <div class="bt-side-label bt-side-a">Side A</div>
                <div class="bt-side-name bt-side-a">{{ $sideAHeadline }}</div>
                @if ($blocA && $blocA !== $sideAHeadline)
                    <div class="bt-side-sub">{{ $blocA }} bloc</div>
                @endif
                <div class="bt-side-sub" style="font-family:'JetBrains Mono',monospace;margin-top:0.15rem;">
                    {{ $tA['pilots'] }} pilots · {{ $tA['kills'] }} kills · {{ $formatIsk($tA['isk_lost']) }} lost
                </div>
            </div>
        </div>

        <div class="bt-vs-center">
            <div class="bt-vs-label">VS</div>
            <div class="bt-side-label" style="margin-top:0.4rem;">ISK efficiency</div>
            <div class="bt-eff-row">
                <span class="bt-eff-val bt-side-a">{{ $effA }}%</span>
                <span class="bt-eff-sep">—</span>
                <span class="bt-eff-val bt-side-b">{{ $effB }}%</span>
            </div>
        </div>

        <div class="bt-side-head flip">
            @if ($flagB)
                <img src="https://images.evetech.net/alliances/{{ $flagB['alliance_id'] }}/logo?size=128"
                     referrerpolicy="no-referrer" class="bt-side-logo right" alt="">
            @else
                <div class="bt-side-logo right" style="display:flex;align-items:center;justify-content:center;background:rgba(255,56,56,0.08);color:#ff3838;font-weight:900;font-size:1.8rem;">B</div>
            @endif
            <div style="min-width:0;">
                <div class="bt-side-label bt-side-b">Side B</div>
                <div class="bt-side-name bt-side-b">{{ $sideBHeadline }}</div>
                @if ($blocB && $blocB !== $sideBHeadline)
                    <div class="bt-side-sub">{{ $blocB }} bloc</div>
                @endif
                <div class="bt-side-sub" style="font-family:'JetBrains Mono',monospace;margin-top:0.15rem;">
                    {{ $tB['pilots'] }} pilots · {{ $tB['kills'] }} kills · {{ $formatIsk($tB['isk_lost']) }} lost
                </div>
            </div>
        </div>
    </div>

    {{-- 3-side share-of-ISK-destroyed bar. Width per side =
         isk_killed / total_isk_killed. Legend below shows each
         side's efficiency % (isk_killed / (isk_killed + isk_lost))
         so operators see both "who destroyed how much" (the bar)
         and "how well they traded" (the percentages). --}}
    <div class="share-bar">
        @foreach ($sideList as $s)
            <span style="--side-color: {{ $s['color'] }}; width: {{ $s['bar'] }}%"></span>
        @endforeach
    </div>
    <div class="share-legend">
        @foreach ($sideList as $s)
            <span style="--side-color: {{ $s['color'] }};">
                {{ $s['label_override'] ?? ($s['key'] === S::SIDE_C ? 'Third parties' : 'Side '.$s['key']) }}
                · {{ $s['eff'] !== null ? $s['eff'].'% eff' : '—' }}
                · {{ $s['bar'] }}% of destroyed
            </span>
        @endforeach
    </div>

    {{-- Public surface suppresses the "Set your coalition" nag —
         it's a portal-internal call-to-action and the link would
         404 for unauth viewers. Authed portal users with no bloc
         set still see it. --}}
    @if (! $hide_bloc_names && ($viewer === null || $viewer->bloc_unresolved))
        <div style="margin-top: 0.75rem; font-size: 0.7rem; color: #7a7a82;">
            Side labels are inferred from the two largest blocs in this fight.
            <a href="/portal/account-settings" style="color: #4fd0d0; text-decoration: underline;">Set your coalition</a>
            for viewer-relative sides.
        </div>
    @endif
</div>

{{-- ================================================================
     SIDE SUMMARY — compact 2×4 stats tile per side
     ================================================================ --}}
<div class="side-columns">
    @foreach ($sideList as $s)
        @php
            $t = $s['totals'];
            $traded = (float) ($t['isk_killed'] + $t['isk_lost']);
            $eff = $traded > 0 ? round($t['isk_killed'] / $traded * 100, 1) : null;
            $sideLabel = $s['label_override'] ?? ($s['key'] === S::SIDE_C ? 'Third parties' : 'Side '.$s['key']);
        @endphp
        <div class="side-card" style="--side-color: {{ $s['color'] }};">
            <h3>
                {{ $sideLabel }}
                <span class="headline">{{ $s['headline'] }}</span>
                @if ($s['sub'] && $s['sub'] !== $s['headline'])
                    <span class="muted">· {{ $s['sub'] }} bloc</span>
                @endif
            </h3>
            <div class="side-stats">
                <div class="cell"><span class="label">Pilots</span><span class="value">{{ number_format($t['pilots']) }}</span></div>
                <div class="cell"><span class="label">Losses</span><span class="value {{ $t['deaths'] > 0 ? 'loss' : '' }}">{{ number_format($t['deaths']) }}</span></div>
                <div class="cell"><span class="label">Kills</span><span class="value">{{ number_format($t['kills']) }}</span></div>
                <div class="cell"><span class="label">ISK dst</span><span class="value accent">{{ $formatIsk((float) $t['isk_killed']) }}</span></div>
                <div class="cell"><span class="label">FB</span><span class="value">{{ number_format($t['final_blows']) }}</span></div>
                <div class="cell"><span class="label">ISK lst</span><span class="value {{ $t['isk_lost'] > 0 ? 'loss' : '' }}">{{ $formatIsk((float) $t['isk_lost']) }}</span></div>
                <div class="cell"><span class="label">Damage</span><span class="value">{{ number_format($t['damage_done']) }}</span></div>
                <div class="cell"><span class="label">Eff</span><span class="value">{{ $eff !== null ? $eff.'%' : '—' }}</span></div>
            </div>
        </div>
    @endforeach
</div>
{{-- ================================================================
     MOST VALUABLE KILLS per side
     ================================================================ --}}
@if (collect($sideList)->contains(fn ($s) => ! empty($most_valuable_kills[$s['key']])))
<div class="side-columns">
    @foreach ($sideList as $s)
        @php
            $rows = $most_valuable_kills[$s['key']] ?? [];
            $sideLabel = $s['label_override'] ?? ($s['key'] === S::SIDE_C ? 'Third parties' : 'Side '.$s['key']);
        @endphp
        <div class="side-card" style="--side-color: {{ $s['color'] }};">
            <h3>Top kills — {{ $sideLabel }} <span class="muted">· by {{ $s['headline'] }}</span></h3>
            @if ($rows === [])
                <div style="font-size:0.78rem;color:#7a7a82;font-style:italic;">—</div>
            @else
                @foreach ($rows as $km)
                    <div class="km-attacker">
                        <img src="https://images.evetech.net/types/{{ $km['ship_type_id'] }}/render?size=64"
                             referrerpolicy="no-referrer" class="bt-mvk-ship {{ $s['tone'] }}" style="border-color: {{ $s['color'] }}40;" alt="">
                        <div class="km-attacker-info">
                            <div class="km-attacker-name">{{ $km['ship_name'] }}</div>
                            <div class="km-attacker-corp">
                                {{ $km['victim_name'] }}
                                @if ($km['victim_alliance_id'])
                                    / {{ $names[$km['victim_alliance_id']] ?? '#'.$km['victim_alliance_id'] }}
                                @endif
                            </div>
                        </div>
                        <div class="km-attacker-damage" style="font-variant-numeric: tabular-nums;">
                            <span style="color:#e5a900;font-weight:700;">{{ $formatIsk((float) $km['total_value']) }}</span>
                            <div style="font-size:0.6rem;color:#7a7a82;">{{ $km['attacker_count'] }} inv.</div>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    @endforeach
</div>
@endif

{{-- ================================================================
     SHIP COMPOSITION per side
     ================================================================ --}}
<div class="side-columns">
    @foreach ($sideList as $s)
        @php
            $rows = $composition[$s['key']] ?? [];
            $max = 0; foreach ($rows as $r) { if ($r['count'] > $max) $max = $r['count']; }
            $sideLabel = $s['label_override'] ?? ($s['key'] === S::SIDE_C ? 'Third parties' : 'Side '.$s['key']);
        @endphp
        <div class="side-card" style="--side-color: {{ $s['color'] }};">
            <h3>Composition — {{ $sideLabel }} <span class="muted">· {{ $s['headline'] }}</span></h3>
            @if ($rows === [])
                <div style="font-size:0.78rem;color:#7a7a82;font-style:italic;">—</div>
            @else
                @foreach ($rows as $r)
                    @php $pct = $max > 0 ? round($r['count'] / $max * 100) : 0; @endphp
                    <div class="km-item-row">
                        <img src="https://images.evetech.net/types/{{ $r['sample_type_id'] }}/icon?size=32"
                             referrerpolicy="no-referrer" class="km-item-icon" alt="">
                        <div class="km-item-name">{{ $r['class'] }}</div>
                        <div class="bt-comp-bar" style="max-width:120px;">
                            <div style="width: {{ $pct }}%; background: {{ $s['color'] }};"></div>
                        </div>
                        <div class="km-item-value" style="color:#e5e5e7;min-width:30px;font-variant-numeric:tabular-nums;text-align:right;">{{ $r['count'] }}</div>
                    </div>
                @endforeach
            @endif
        </div>
    @endforeach
</div>

{{-- ================================================================
     TOP DAMAGE per side
     ================================================================ --}}
<div class="side-columns">
    @foreach ($sideList as $s)
        @php
            $rows = $top_damage[$s['key']] ?? [];
            $sideLabel = $s['label_override'] ?? ($s['key'] === S::SIDE_C ? 'Third parties' : 'Side '.$s['key']);
        @endphp
        <div class="side-card" style="--side-color: {{ $s['color'] }};">
            <h3>Top damage — {{ $sideLabel }}</h3>
            @if ($rows === [])
                <div style="font-size:0.78rem;color:#7a7a82;font-style:italic;">—</div>
            @else
                @foreach ($rows as $r)
                    <div class="km-attacker">
                        <img src="https://images.evetech.net/characters/{{ $r['character_id'] }}/portrait?size=64"
                             referrerpolicy="no-referrer" class="km-attacker-portrait" alt="">
                        <div class="km-attacker-info">
                            <div class="km-attacker-name">{{ $r['character_name'] }}{!! $roleBadge((int) $r['character_id']) !!}</div>
                            <div class="km-attacker-corp">
                                @if ($r['alliance_name']) {{ $r['alliance_name'] }} @endif
                            </div>
                            @if (! empty($r['ships_flown']))
                                <div class="km-attacker-ship" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
                                    @foreach ($r['ships_flown'] as $s)
                                        <span style="display:inline-flex;align-items:center;gap:3px;" title="{{ $s['count'] }}× {{ $s['name'] }} · {{ number_format($s['damage']) }} dmg">
                                            <img src="https://images.evetech.net/types/{{ $s['type_id'] }}/icon?size=32"
                                                 referrerpolicy="no-referrer" style="width:16px;height:16px;border-radius:2px;" alt="">
                                            {{ $s['name'] }}@if ($s['count'] > 1)<span style="color:#7a7a82;font-size:0.7em;"> ×{{ $s['count'] }} kills</span>@endif@if ($s['damage'] > 0)<span style="color:#9ca0a8;font-size:0.7em;"> ({{ number_format($s['damage']) }})</span>@endif
                                        </span>
                                    @endforeach
                                </div>
                            @elseif ($r['ship_type_id'])
                                <div class="km-attacker-ship">
                                    <img src="https://images.evetech.net/types/{{ $r['ship_type_id'] }}/icon?size=32"
                                         referrerpolicy="no-referrer" style="width:16px;height:16px;border-radius:2px;" alt="">
                                    {{ $r['ship_name'] }}
                                </div>
                            @endif
                        </div>
                        <div class="km-attacker-damage">
                            {{ number_format($r['damage_done']) }}
                            <div style="font-size:0.6rem;color:#7a7a82;">
                                dmg · {{ $r['kills'] }}k
                                @if ($r['final_blows'] > 0) / {{ $r['final_blows'] }}fb @endif
                            </div>
                            @if (! empty($r['damage_taken']) || ! empty($r['isk_lost']))
                                <div style="font-size:0.6rem;color:#9ca0a8;margin-top:3px;">
                                    @if (! empty($r['damage_taken'])) took {{ number_format($r['damage_taken']) }} @endif
                                    @if (! empty($r['isk_lost']))
                                        · lost {{ $formatIsk((float) $r['isk_lost']) }}
                                    @endif
                                    @if (! empty($r['pod_losses']))
                                        · pod{{ $r['pod_losses'] > 1 ? 's' : '' }}: {{ $r['pod_losses'] }}
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    @endforeach
</div>

{{-- ================================================================
     ALLIANCE ROSTER per side
     ================================================================ --}}
<div class="side-columns">
    @foreach ($sideList as $s)
        @php
            $rows = $roster_by_side[$s['key']] ?? collect();
            $sideLabel = $s['label_override'] ?? ($s['key'] === S::SIDE_C ? 'Third parties' : 'Side '.$s['key']);
        @endphp
        <div class="side-card" style="--side-color: {{ $s['color'] }};">
            <h3>Roster — {{ $sideLabel }} <span class="muted">· {{ $rows->count() }}</span></h3>
            @if ($rows->isEmpty())
                <div style="font-size:0.78rem;color:#7a7a82;font-style:italic;">No alliances.</div>
            @else
                @foreach ($rows as $row)
                    @php
                        $currentOverride = $row['alliance_id'] > 0
                            ? ($override_by_alliance[(int) $row['alliance_id']] ?? null)
                            : null;
                    @endphp
                    <div class="km-attacker">
                        @if ($row['alliance_id'] > 0)
                            <img src="https://images.evetech.net/alliances/{{ $row['alliance_id'] }}/logo?size=64"
                                 referrerpolicy="no-referrer" class="km-attacker-portrait" style="border-radius: 4px;" alt="">
                        @else
                            <div class="km-attacker-portrait" style="border-radius: 4px; background: #1a1a1e;"></div>
                        @endif
                        <div class="km-attacker-info">
                            <div class="km-attacker-name">
                                {{ $row['alliance_name'] }}
                                @if ($currentOverride)
                                    <span class="km-badge km-badge-cyan" style="margin-left: 4px;">manual → {{ $currentOverride }}</span>
                                @endif
                            </div>
                            <div class="km-attacker-corp">
                                {{ $row['pilots'] }}p · {{ $row['kills'] }}k · {{ $row['deaths'] }}d
                            </div>
                        </div>
                        <div class="km-attacker-damage">
                            <span style="color: {{ $row['isk_lost'] > 0 ? '#ff3838' : '#7a7a82' }};">{{ $formatIsk((float) $row['isk_lost']) }}</span>
                            <div style="font-size:0.6rem;color:#7a7a82;">isk lost</div>
                        </div>
                    </div>
                    @if ($can_override && $row['alliance_id'] > 0)
                        <div style="display: flex; gap: 0.3rem; align-items: center; padding: 0 0.3rem 0.4rem 2.9rem; font-size: 0.7rem; color: #7a7a82;">
                            <label style="font-family:'JetBrains Mono',monospace; font-size: 0.6rem; letter-spacing: 0.08em; text-transform: uppercase;">move →</label>
                            <form method="POST"
                                  action="{{ route('portal.battles.overrides.store', ['record' => $theater->id]) }}"
                                  style="display: inline;">
                                @csrf
                                <input type="hidden" name="entity_type" value="alliance">
                                <input type="hidden" name="entity_id" value="{{ $row['alliance_id'] }}">
                                <select name="side"
                                        onchange="this.form.submit()"
                                        style="background:#0c0c0e;color:#e5e5e7;border:1px solid #26262b;border-radius:3px;padding:0.15rem 0.3rem;font-size:0.7rem;font-family:'JetBrains Mono',monospace;">
                                    <option value="" disabled {{ $currentOverride ? '' : 'selected' }}>— auto —</option>
                                    <option value="A" {{ $currentOverride === 'A' ? 'selected' : '' }}>Side A</option>
                                    <option value="B" {{ $currentOverride === 'B' ? 'selected' : '' }}>Side B</option>
                                    <option value="C" {{ $currentOverride === 'C' ? 'selected' : '' }}>Third party</option>
                                    <option value="exclude" {{ $currentOverride === 'exclude' ? 'selected' : '' }}>Exclude</option>
                                </select>
                            </form>
                            @if ($currentOverride)
                                <form method="POST"
                                      action="{{ route('portal.battles.overrides.destroy', ['record' => $theater->id]) }}"
                                      style="display: inline;">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="entity_type" value="alliance">
                                    <input type="hidden" name="entity_id" value="{{ $row['alliance_id'] }}">
                                    <button type="submit"
                                            style="background: transparent; border: 1px solid #26262b; color: #7a7a82; border-radius: 3px; padding: 0.1rem 0.4rem; font-size: 0.6rem; cursor: pointer;">
                                        clear
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endif
                @endforeach
            @endif
        </div>
    @endforeach
</div>

{{-- ================================================================
     KILL FEED — narrative row per kill, ordered by time
     ================================================================ --}}
<div class="km-card" style="margin-bottom: 1.5rem;">
    <details class="bt-killfeed">
        <summary>
            <h3 style="margin:0;display:inline;">Kill feed <span class="muted">· {{ count($kill_feed) }} kills</span></h3>
            <span class="bt-killfeed-hint">click to expand</span>
        </summary>
    @foreach ($kill_feed as $km)
        @php
            $vSide       = $km['victim_id'] ? ($sides->sideByCharacterId[(int) $km['victim_id']] ?? 'C') : 'C';
            $sideClass   = 'bt-kill-'.strtolower($vSide);
            $isHighValue = $km['total_value'] >= 1_000_000_000;
            $highClass   = $isHighValue ? 'bt-kill-high' : '';
        @endphp
        <div class="km-attacker {{ $sideClass }} {{ $highClass }}">
            <div class="bt-kill-time">{{ \Carbon\Carbon::parse($km['killed_at'])->format('H:i:s') }}</div>

            @if ($km['victim_id'])
                <img src="https://images.evetech.net/characters/{{ $km['victim_id'] }}/portrait?size=64"
                     referrerpolicy="no-referrer" class="km-attacker-portrait" alt="">
            @endif

            @if ($km['ship_type_id'] > 0)
                <img src="https://images.evetech.net/types/{{ $km['ship_type_id'] }}/icon?size=32"
                     referrerpolicy="no-referrer" style="width:32px;height:32px;border-radius:3px;flex-shrink:0;" alt="">
            @endif

            <div class="km-attacker-info">
                <div class="km-attacker-name">
                    {{ $km['victim_name'] }}
                    @if ($km['victim_id'])
                        {!! $roleBadgeForKm((int) $km['killmail_id'], (int) $km['victim_id']) !!}
                    @endif
                </div>
                <div class="km-attacker-corp">
                    lost a <span style="color:#e5e5e7;">{{ $km['ship_name'] }}</span>@if (! empty($km['pod_ship_type_id']))<span style="color:#9ca0a8;"> + Capsule</span>@endif
                    @if ($km['victim_alliance_id'])
                        · {{ $names[$km['victim_alliance_id']] ?? '#'.$km['victim_alliance_id'] }}
                    @endif
                    · {{ $km['attacker_count'] }} involved
                </div>
                @if ($km['final_blow_name'])
                    <div class="km-attacker-ship">
                        final blow:
                        <span style="color:#e5e5e7;">{{ $km['final_blow_name'] }}</span>
                        @if (! empty($km['final_blow_char_id']))
                            {!! $roleBadgeForKm((int) $km['killmail_id'], (int) $km['final_blow_char_id']) !!}
                        @endif
                        @if ($km['final_blow_alliance_id'])
                            ({{ $names[$km['final_blow_alliance_id']] ?? '#'.$km['final_blow_alliance_id'] }})
                        @endif
                        @if ($km['final_blow_ship_id'])
                            — {{ $ship_names[$km['final_blow_ship_id']] ?? '#'.$km['final_blow_ship_id'] }}
                        @endif
                    </div>
                @endif
            </div>

            <div class="km-attacker-damage">
                <span style="color: {{ $isHighValue ? '#e5a900' : '#e5e5e7' }}; font-weight: {{ $isHighValue ? 700 : 400 }};">
                    {{ $km['total_value'] > 0 ? $formatIsk((float) $km['total_value']) : '—' }}
                </span>
                <div style="font-size:0.6rem;color:#7a7a82;">isk</div>
            </div>
        </div>
    @endforeach
    </details>
</div>

{{-- ================================================================
     PILOTS — grouped by side, km-attacker rows
     ================================================================ --}}
@php
    // Build character_id => killmail_id lookup from the kill feed so
    // pilots with a loss in this theater get a clickable row.
    $lossKmByChar = [];
    foreach ($kill_feed as $km) {
        if (! empty($km['victim_id'])) {
            $lossKmByChar[(int) $km['victim_id']] = (int) $km['killmail_id'];
        }
    }
    $killmailUrl = function (int $kmId) use ($hide_bloc_names): string {
        return $hide_bloc_names
            ? "/kills/{$kmId}"
            : "/portal/killmails/{$kmId}";
    };
@endphp
<div class="side-columns">
    @foreach ($sideList as $s)
        @php
            $sidePilots = $participants->filter(fn ($p) => ($sides->sideByCharacterId[(int) $p->character_id] ?? 'C') === $s['key'])->values();
            $sideLabel = $s['label_override'] ?? ($s['key'] === S::SIDE_C ? 'Third parties' : 'Side '.$s['key']);
        @endphp
        <div class="side-card" style="--side-color: {{ $s['color'] }};">
            <h3>Pilots — {{ $sideLabel }} <span class="muted">· {{ $sidePilots->count() }}</span></h3>
            <div class="bt-pilot-group-head {{ $s['tone'] }}" style="color: {{ $s['color'] }};">
                @if ($s['headline']) <span style="color:#e5e5e7;font-weight:600;letter-spacing:0.05em;text-transform:none;">{{ $s['headline'] }}</span> @endif
            </div>
            @if ($sidePilots->isEmpty())
                <div style="font-size:0.78rem;color:#7a7a82;font-style:italic;">No pilots.</div>
            @else
                @foreach ($sidePilots as $p)
                    @php
                        $cid       = (int) $p->character_id;
                        $cName     = $names[$cid] ?? 'Character #'.$cid;
                        $aName     = $p->alliance_id ? ($names[(int) $p->alliance_id] ?? '#'.$p->alliance_id) : null;
                        $allShips  = $allShipsOf($cid);
                        $ship      = $primaryShipOf($cid);
                        $isFB      = $p->final_blows > 0;
                        $lossKm    = $lossKmByChar[$cid] ?? null;
                    @endphp
                    @if ($lossKm)
                    <a href="{{ $killmailUrl($lossKm) }}" class="bt-pilot-link">
                    @endif
                    <div class="km-attacker {{ $isFB ? 'km-final-blow' : '' }} {{ $lossKm ? 'bt-pilot-clickable' : '' }}">
                        <img src="https://images.evetech.net/characters/{{ $cid }}/portrait?size=64"
                             referrerpolicy="no-referrer" class="km-attacker-portrait" alt="">
                        <div class="km-attacker-info">
                            <div class="km-attacker-name">
                                {{ $cName }}
                                {!! $roleBadge((int) $cid) !!}
                                @if ($isFB) <span class="km-badge km-badge-red" style="margin-left: 4px;">FB × {{ $p->final_blows }}</span> @endif
                            </div>
                            <div class="km-attacker-corp">{{ $aName ?? '—' }}</div>
                            @if ($allShips !== [])
                                <div class="km-attacker-ship" style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;">
                                    @foreach ($allShips as $s)
                                        <span style="display:inline-flex;align-items:center;gap:3px;" title="{{ $s['count'] }}× {{ $s['name'] }} kills">
                                            <img src="https://images.evetech.net/types/{{ $s['type_id'] }}/icon?size=32"
                                                 referrerpolicy="no-referrer" style="width:16px;height:16px;border-radius:2px;" alt="">
                                            {{ $s['name'] }}@if ($s['count'] > 1) <span style="color:#7a7a82;font-size:0.7em;">×{{ $s['count'] }} kills</span>@endif
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="km-attacker-damage">
                            @if ($p->damage_done > 0)
                                <div>{{ number_format($p->damage_done) }} <span style="font-size:0.6rem;color:#7a7a82;">dmg</span></div>
                            @endif
                            @if ($p->kills > 0)
                                <div style="color:#4ade80;">{{ $p->kills }}k</div>
                            @endif
                            @if ($p->deaths > 0)
                                <div style="color:#ff3838;">{{ $formatIsk((float) $p->isk_lost) }}</div>
                            @endif
                        </div>
                    </div>
                    @if ($lossKm)
                    </a>
                    @endif
                @endforeach
            @endif
        </div>
    @endforeach
</div>

{{-- ================================================================
     FC ATTESTATION STRIP (Spec 6, donor-only)

     Compact per-sub-fleet strip with a "Mark FC" control for donor
     users. Inline role badges next to pilot names elsewhere in the
     report carry the visual burden of showing inferred roles; this
     card exists only so donors can label truth data for Spec 7.
     ================================================================ --}}
@auth
@if (auth()->user()?->isDonor())
@php
    $roleInference = $role_inference_by_alliance ?? [];
    $allianceName = fn (int $aid) => $names[$aid] ?? ('Alliance #' . $aid);
@endphp
@if (! empty($roleInference))
<div class="km-card" style="margin-bottom: 1.5rem;">
    <h3>Mark FC <span class="muted">· donor calibration feed</span></h3>
    <p class="muted" style="font-size:.8em; margin:.25rem 0 .5rem;">
        Your marks are private (only you see them). They train Spec 7 calibration.
    </p>
    @foreach ($roleInference as $allianceId => $subFleets)
        @foreach ($subFleets as $sfid => $sf)
            <div style="padding:.35rem .5rem; margin-bottom:.35rem; background:rgba(255,255,255,0.02); border-left:2px solid #3b3f48; border-radius:0 3px 3px 0;">
                <div style="font-size:.8em; color:#9ca3af;">
                    {{ $allianceName((int)$allianceId) }} · Sub-fleet {{ $sfid }} · {{ $sf['member_count'] }}p
                </div>
                @livewire('battle-fc-attest',
                    [
                        'battleId' => $theater->id,
                        'allianceId' => (int) $allianceId,
                        'subFleetId' => (int) $sfid,
                        'partitionAlgoVersion' => (int) $sf['partition_algo_version'],
                        'candidates' => collect($sf['members'] ?? [])->map(fn($m) => [
                            'character_id' => $m['character_id'],
                            'character_name' => $m['character_name'],
                            'ship_name' => $m['ship_name'] ?? null,
                            'ship_class_category' => $m['ship_class_category'] ?? null,
                        ])->all(),
                    ],
                    key('fc-attest-'.$theater->id.'-'.$allianceId.'-'.$sfid)
                )
            </div>
        @endforeach
    @endforeach
</div>
@endif
@endif
@endauth

{{-- ================================================================
     SYSTEMS
     ================================================================ --}}
<div class="km-card" style="margin-bottom: 1.5rem;">
    <h3>Systems <span class="muted">· {{ $systems->count() }}</span></h3>
    @foreach ($systems as $s)
        @php
            $sSec = (float) ($s->solarSystem?->security_status ?? 0.0);
            $sColor = $sSec >= 0.5 ? '#4ade80' : ($sSec >= 0.0 ? '#e5a900' : '#ff3838');
        @endphp
        <div class="km-stat">
            <span class="km-stat-label">
                <span style="color: {{ $sColor }}; font-family: 'JetBrains Mono', monospace; margin-right: 0.4rem;">{{ number_format($sSec, 1) }}</span>
                <span style="color:#e5e5e7;">{{ $s->solarSystem?->name ?? '#'.$s->solar_system_id }}</span>
                <span style="color:#7a7a82;margin-left:0.5rem;">{{ $s->kill_count }} kills</span>
            </span>
            <span class="km-stat-value">
                <span style="color: {{ $s->isk_lost > 0 ? '#ff3838' : '#7a7a82' }};">{{ $formatIsk((float) $s->isk_lost) }}</span>
                <span style="color:#7a7a82;margin-left:0.75rem;">{{ $s->first_kill_at?->format('H:i') }} → {{ $s->last_kill_at?->format('H:i') }}</span>
            </span>
        </div>
    @endforeach
</div>


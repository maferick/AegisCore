@php
    $victimName = $km->victim_character_id
        ? ($names[$km->victim_character_id] ?? 'Pilot #'.$km->victim_character_id)
        : ($km->victim_ship_type_name ?? $typeNames[$km->victim_ship_type_id] ?? 'Unknown');
    $victim = [
        'name' => $victimName,
        'corp' => $km->victim_corporation_id ? ($names[$km->victim_corporation_id] ?? 'Corp #'.$km->victim_corporation_id) : null,
        'alliance' => $km->victim_alliance_id ? ($names[$km->victim_alliance_id] ?? 'Alliance #'.$km->victim_alliance_id) : null,
    ];

    $formatIsk = function (float $v): string {
        if ($v >= 1e12) return number_format($v / 1e12, 2) . ' T';
        if ($v >= 1e9)  return number_format($v / 1e9, 2) . ' B';
        if ($v >= 1e6)  return number_format($v / 1e6, 2) . ' M';
        if ($v >= 1e3)  return number_format($v / 1e3, 1) . ' K';
        return number_format($v, 0);
    };

    $slotLabels = [
        'high' => 'High Slots', 'mid' => 'Mid Slots', 'low' => 'Low Slots',
        'rig' => 'Rigs', 'subsystem' => 'Subsystems', 'service' => 'Service Slots',
        'drone_bay' => 'Drones', 'fighter_bay' => 'Fighters', 'cargo' => 'Cargo',
        'implant' => 'Implants', 'other' => 'Other',
    ];

    $slotOrder = ['high','mid','low','rig','subsystem','service','drone_bay','fighter_bay','cargo','implant','other'];

    // Per-killmail role tags — passed in from KillmailViewData as
    // roleByCharacter[character_id => role_key]. Render as a small
    // pill next to pilot names (victim + each attacker).
    $roleByChar = $roleByCharacter ?? [];
    $roleLabel = fn (string $r): string => match ($r) {
        'fc' => 'FC', 'logi' => 'Logi', 'bomber' => 'Bomber',
        'command' => 'Cmd', 'tackle' => 'Tackle', 'mainline_dps' => 'DPS',
        default => '',
    };
    $roleStyle = fn (string $r): string => match ($r) {
        'fc' => 'background:rgba(202,138,4,0.25);color:#fde047;border:1px solid rgba(250,204,21,0.35);',
        'logi' => 'background:rgba(5,150,105,0.2);color:#6ee7b7;border:1px solid rgba(16,185,129,0.3);',
        'bomber' => 'background:rgba(234,88,12,0.25);color:#fdba74;border:1px solid rgba(249,115,22,0.35);',
        'command' => 'background:rgba(168,85,247,0.25);color:#f0abfc;border:1px solid rgba(192,132,252,0.35);',
        'tackle' => 'background:rgba(8,145,178,0.25);color:#67e8f9;border:1px solid rgba(14,165,233,0.35);',
        'mainline_dps' => 'background:rgba(30,64,175,0.2);color:#93c5fd;border:1px solid rgba(59,130,246,0.3);',
        default => '',
    };
    $roleBadge = function (?int $cid) use ($roleByChar, $roleLabel, $roleStyle): string {
        if ($cid === null) return '';
        $role = $roleByChar[$cid] ?? null;
        if ($role === null) return '';
        $label = $roleLabel($role);
        if ($label === '') return '';
        return '<span style="display:inline-block;padding:1px 5px;margin-left:5px;font-size:0.6rem;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;line-height:1;border-radius:8px;vertical-align:middle;'
            . $roleStyle($role) . '">' . $label . '</span>';
    };

    /* ---------- Fitting wheel -------------------------------------
       Render the victim's hull as a zKill-style circle: ship render
       in the middle, concentric rings of slot bezels, each fitted
       module as an absolutely-positioned 32px icon on the right arc.
       Inner ring = subsystem / service slots (T3Cs, capitals). Outer
       ring = high / mid / low / rig arcs.

       Slot counts come from ref_type_dogma JSON (attribute 14/13/12/
       1154/1367/2056). Falls back to the count of actually-fitted
       modules when the dogma lookup misses. */
    $wheelShipTypeId = (int) $km->victim_ship_type_id;
    $wheelDogmaRow = \Illuminate\Support\Facades\DB::table('ref_type_dogma')
        ->where('id', $wheelShipTypeId)->value('data');
    $wheelAttrs = [];
    if ($wheelDogmaRow) {
        $decoded = json_decode((string) $wheelDogmaRow, true);
        foreach (($decoded['dogmaAttributes'] ?? []) as $a) {
            $wheelAttrs[(int) ($a['attributeID'] ?? 0)] = (float) ($a['value'] ?? 0);
        }
    }
    // Group → fitted modules (charges excluded, ordered by flag).
    $wheelSlotKeys = ['high', 'mid', 'low', 'rig', 'subsystem', 'service'];
    $wheelModulesByGroup = [];
    foreach ($wheelSlotKeys as $k) {
        $items = $itemsBySlot[$k] ?? collect();
        $mods = $items->reject(fn ($i) => $chargeTypeIds->has($i->type_id))
            ->sortBy('flag')->values();
        $wheelModulesByGroup[$k] = $mods->all();
    }
    // Slot-count per group. Dogma attrs report the hull's base slot
    // layout but T3Cs publish 0 for hi/mid/low because their totals
    // depend on the subsystems fitted — so fall back to the number
    // of actually-fitted modules whenever the attr reports ≤ 0.
    $wheelCountFrom = function (int $attr, int $fittedCount) use ($wheelAttrs): int {
        $v = (int) ($wheelAttrs[$attr] ?? 0);
        return $v > 0 ? $v : $fittedCount;
    };
    $wheelCounts = [
        'high'      => $wheelCountFrom(14,   count($wheelModulesByGroup['high'])),
        'mid'       => $wheelCountFrom(13,   count($wheelModulesByGroup['mid'])),
        'low'       => $wheelCountFrom(12,   count($wheelModulesByGroup['low'])),
        'rig'       => $wheelCountFrom(1154, count($wheelModulesByGroup['rig'])),
        'subsystem' => $wheelCountFrom(1367, count($wheelModulesByGroup['subsystem'])),
        'service'   => $wheelCountFrom(2056, count($wheelModulesByGroup['service'])),
    ];
    // Layout: outer ring = high/mid/low/rig, inner ring = subsystem/
    // service. Arcs are degrees, 0 = right, -90 = top. Starts from
    // zKill's known-good ranges and stretches/shrinks automatically
    // for n slots.
    $wheelLayout = [];
    if ($wheelCounts['high'] > 0)      $wheelLayout[] = ['key' => 'high',      'count' => $wheelCounts['high'],      'ring' => 'outer', 'arc' => [-140, -40]];
    if ($wheelCounts['mid'] > 0)       $wheelLayout[] = ['key' => 'mid',       'count' => $wheelCounts['mid'],       'ring' => 'outer', 'arc' => [-30,   40]];
    if ($wheelCounts['low'] > 0)       $wheelLayout[] = ['key' => 'low',       'count' => $wheelCounts['low'],       'ring' => 'outer', 'arc' => [ 50,  150]];
    if ($wheelCounts['rig'] > 0)       $wheelLayout[] = ['key' => 'rig',       'count' => $wheelCounts['rig'],       'ring' => 'outer', 'arc' => [160,  210]];
    if ($wheelCounts['subsystem'] > 0) $wheelLayout[] = ['key' => 'subsystem', 'count' => $wheelCounts['subsystem'], 'ring' => 'inner', 'arc' => [-135,   135]];
    if ($wheelCounts['service'] > 0)   $wheelLayout[] = ['key' => 'service',   'count' => $wheelCounts['service'],   'ring' => 'inner', 'arc' => [-180,  180]];

    $wheelPositions = function (int $count, array $arc, float $radius, float $cx, float $cy): array {
        [$start, $end] = $arc;
        $step = $count === 1 ? 0 : ($end - $start) / ($count - 1);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $deg = $start + $step * $i;
            $rad = deg2rad($deg);
            $out[] = [
                'x' => $cx + $radius * cos($rad),
                'y' => $cy + $radius * sin($rad),
            ];
        }
        return $out;
    };

    $wheelGroupColor = fn (string $g): string => match ($g) {
        'high'      => '#f4c542',
        'mid'       => '#4a9eff',
        'low'       => '#d94f4f',
        'rig'       => '#7ddc8b',
        'subsystem' => '#b98cff',
        'service'   => '#ffa94d',
        default     => '#7a7a82',
    };

    $wheelHasAny = array_sum($wheelCounts) > 0;
@endphp

<style>
    .km-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
    @media (max-width: 768px) { .km-grid { grid-template-columns: 1fr; } }
    .km-card { background: rgba(17,17,19,0.6); border: 1px solid #26262b; border-radius: 8px; padding: 1.25rem; }
    .km-card h3 { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.12em; color: #7a7a82; margin-bottom: 0.75rem; font-family: 'JetBrains Mono', monospace; }
    .km-victim-header { display: flex; gap: 1.25rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.5rem; }
    .km-ship-render { border-radius: 8px; border: 2px solid rgba(79,208,208,0.25); }
    .km-victim-name { font-size: 1.4rem; font-weight: 700; color: #e5e5e7; }
    .km-victim-meta { font-size: 0.8rem; color: #7a7a82; }
    .km-victim-meta img { width: 18px; height: 18px; border-radius: 3px; vertical-align: middle; margin-right: 3px; }
    .km-stat { display: flex; justify-content: space-between; padding: 0.3rem 0; border-bottom: 1px solid #1a1a1e; }
    .km-stat:last-child { border-bottom: none; }
    .km-stat-label { font-size: 0.78rem; color: #7a7a82; }
    .km-stat-value { font-size: 0.78rem; font-family: 'JetBrains Mono', monospace; color: #e5e5e7; }
    .km-stat-value.isk { color: #e5a900; }
    .km-stat-value.total { color: #ff3838; font-weight: 700; font-size: 0.9rem; }
    .km-item-row { display: flex; align-items: center; gap: 0.5rem; padding: 0.35rem 0; border-bottom: 1px solid #1a1a1e; }
    .km-item-row:last-child { border-bottom: none; }
    .km-item-icon { width: 28px; height: 28px; border-radius: 3px; flex-shrink: 0; }
    .km-item-name { flex: 1; font-size: 0.78rem; color: #e5e5e7; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .km-item-qty { font-family: 'JetBrains Mono', monospace; font-size: 0.72rem; color: #7a7a82; }
    .km-item-value { font-family: 'JetBrains Mono', monospace; font-size: 0.72rem; color: #e5a900; text-align: right; min-width: 70px; }
    .km-item-destroyed { color: #ff3838; }
    .km-item-dropped { color: #4ade80; }
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
    .km-zkill-link {
        display: inline-block; margin-left: auto;
        padding: 0.25rem 0.6rem; border-radius: 4px;
        background: rgba(79,208,208,0.08); border: 1px solid rgba(79,208,208,0.25);
        color: #4fd0d0; font-size: 0.72rem; text-decoration: none;
        font-family: 'JetBrains Mono', monospace;
    }
    .km-zkill-link:hover { background: rgba(79,208,208,0.15); }

    /* Fitting wheel — 400×400 circle, ship render center, module
       icons on concentric arcs. zKillboard-style but SVG-driven so
       bezels adapt to any hull slot layout. */
    .km-fit-wrap {
        display: flex; gap: 1.5rem; align-items: center; flex-wrap: wrap;
        margin: 0.5rem 0 1.5rem; justify-content: center;
    }
    .km-fit-wheel { position: relative; width: 400px; height: 400px; flex-shrink: 0; }
    .km-fit-wheel > svg { position: absolute; inset: 0; pointer-events: none; }
    .km-fit-wheel > .km-fit-ship {
        position: absolute; left: 72px; top: 72px;
        width: 256px; height: 256px; border-radius: 50%;
        mask-image: radial-gradient(circle, black 60%, transparent 72%);
        -webkit-mask-image: radial-gradient(circle, black 60%, transparent 72%);
    }
    .km-fit-mod {
        position: absolute; width: 32px; height: 32px; border-radius: 3px;
        background: rgba(17,17,19,0.7);
        transition: transform 0.1s ease, box-shadow 0.1s ease;
    }
    .km-fit-mod:hover { transform: scale(1.18); z-index: 5; }
    .km-fit-mod.dropped { box-shadow: 0 0 0 1.5px rgba(74,222,128,0.75); }
    .km-fit-mod.destroyed { box-shadow: 0 0 0 1.5px rgba(255,56,56,0.6); filter: grayscale(0.3); }
    .km-fit-charge {
        position: absolute; width: 16px; height: 16px;
        border-radius: 2px; pointer-events: none;
    }
    .km-fit-legend {
        display: flex; gap: 0.75rem; flex-wrap: wrap;
        font-family: 'JetBrains Mono', monospace; font-size: 0.65rem;
        color: #7a7a82;
    }
    .km-fit-legend span { display: inline-flex; align-items: center; gap: 4px; }
    .km-fit-legend span::before {
        content: ''; display: inline-block; width: 10px; height: 10px;
        border-radius: 2px; background: var(--tone, #7a7a82);
    }
</style>

{{-- Victim header --}}
<div class="km-victim-header">
    <img src="https://images.evetech.net/types/{{ $km->victim_ship_type_id }}/render?size=128"
         alt="{{ $km->victim_ship_type_name }}" referrerpolicy="no-referrer"
         class="km-ship-render" width="96" height="96">
    <div style="flex:1;min-width:0;">
        <div class="km-victim-name">{{ $victim['name'] }}{!! $roleBadge($km->victim_character_id ? (int) $km->victim_character_id : null) !!}</div>
        <div class="km-victim-meta" style="margin-top: 0.25rem;">
            {{ $km->victim_ship_type_name ?? $typeNames[$km->victim_ship_type_id] ?? 'Unknown Ship' }}
            <span style="color: #3a3a42; margin: 0 0.3rem;">&middot;</span>
            {{ $km->victim_ship_group_name ?? '' }}
        </div>
        <div class="km-victim-meta" style="margin-top: 0.35rem;">
            @php
                $victimEtCorp = $km->victim_character_id ? ($eventTimeCorps[$km->victim_character_id] ?? null) : null;
            @endphp
            @if($victimEtCorp && $victimEtCorp['corporation_id'] != $km->victim_corporation_id && $victimEtCorp['corporation_name'])
                <img src="https://images.evetech.net/corporations/{{ $victimEtCorp['corporation_id'] }}/logo?size=32"
                     alt="" referrerpolicy="no-referrer">
                <span style="color: #e5a900;">{{ $victimEtCorp['corporation_name'] }}</span>
                <span style="font-size: 0.6rem; color: #3a3a42;">(now: {{ $victim['corp'] }})</span>
            @elseif($km->victim_corporation_id)
                <img src="https://images.evetech.net/corporations/{{ $km->victim_corporation_id }}/logo?size=32"
                     alt="" referrerpolicy="no-referrer">
                {{ $victim['corp'] ?? 'Corp #'.$km->victim_corporation_id }}
            @endif
            @php
                $victimEtCorpId = $victimEtCorp['corporation_id'] ?? $km->victim_corporation_id;
                $victimEtAlly = ($eventTimeAlliances[$victimEtCorpId] ?? null);
            @endphp
            @if($victimEtAlly && $victimEtAlly['alliance_id'] != $km->victim_alliance_id && $victimEtAlly['alliance_name'])
                <span style="margin: 0 0.2rem;">/</span>
                <img src="https://images.evetech.net/alliances/{{ $victimEtAlly['alliance_id'] }}/logo?size=32"
                     alt="" referrerpolicy="no-referrer">
                <span style="color: #e5a900;">{{ $victimEtAlly['alliance_name'] }}</span>
                @if($km->victim_alliance_id)
                    <span style="font-size: 0.6rem; color: #3a3a42;">(now: {{ $victim['alliance'] ?? '#'.$km->victim_alliance_id }})</span>
                @endif
            @elseif($km->victim_alliance_id)
                <span style="margin: 0 0.2rem;">/</span>
                <img src="https://images.evetech.net/alliances/{{ $km->victim_alliance_id }}/logo?size=32"
                     alt="" referrerpolicy="no-referrer">
                {{ $victim['alliance'] ?? 'Alliance #'.$km->victim_alliance_id }}
            @endif
        </div>
        <div class="km-victim-meta" style="margin-top: 0.35rem;">
            {{ $systemName }} &middot; {{ $regionName }}
            <span style="margin-left: 0.5rem;">{{ $km->killed_at->format('M d, Y H:i') }} UTC</span>
        </div>
    </div>
    <a class="km-zkill-link"
       href="https://zkillboard.com/kill/{{ $km->killmail_id }}/"
       target="_blank" rel="noopener">
        View on zKillboard ↗
    </a>
</div>

@if ($wheelHasAny)
@php
    $wheelCx = 200.0; $wheelCy = 200.0;
    $wheelOuterR = 165.0; $wheelInnerR = 86.0;
@endphp
<div class="km-fit-wrap">
    <div class="km-fit-wheel" aria-hidden="true">
        <svg width="400" height="400">
            <circle cx="{{ $wheelCx }}" cy="{{ $wheelCy }}" r="{{ $wheelOuterR + 18 }}" fill="none" stroke="rgba(255,255,255,0.05)" />
            <circle cx="{{ $wheelCx }}" cy="{{ $wheelCy }}" r="{{ $wheelInnerR + 18 }}" fill="none" stroke="rgba(255,255,255,0.04)" />
            @foreach ($wheelLayout as $g)
                @php
                    $r = $g['ring'] === 'outer' ? $wheelOuterR : $wheelInnerR;
                    $positions = $wheelPositions($g['count'], $g['arc'], $r, $wheelCx, $wheelCy);
                    $color = $wheelGroupColor($g['key']);
                @endphp
                @foreach ($positions as $p)
                    <circle cx="{{ number_format($p['x'], 2, '.', '') }}" cy="{{ number_format($p['y'], 2, '.', '') }}" r="18"
                            fill="rgba(17,17,19,0.5)" stroke="{{ $color }}" stroke-opacity="0.5" stroke-width="1.5" />
                @endforeach
            @endforeach
        </svg>

        <img class="km-fit-ship" referrerpolicy="no-referrer"
             src="https://images.evetech.net/types/{{ $wheelShipTypeId }}/render?size=256"
             alt="{{ $km->victim_ship_type_name ?? '' }}">

        @foreach ($wheelLayout as $g)
            @php
                $r = $g['ring'] === 'outer' ? $wheelOuterR : $wheelInnerR;
                $positions = $wheelPositions($g['count'], $g['arc'], $r, $wheelCx, $wheelCy);
                $mods = $wheelModulesByGroup[$g['key']] ?? [];
            @endphp
            @foreach ($positions as $i => $p)
                @php $m = $mods[$i] ?? null; @endphp
                @if ($m)
                    @php
                        $dropped = ($m->quantity_dropped ?? 0) > 0;
                        $destroyed = ($m->quantity_destroyed ?? 0) > 0;
                        $stateClass = $dropped ? 'dropped' : ($destroyed ? 'destroyed' : '');
                        $modName = $m->type_name ?? $typeNames[$m->type_id] ?? 'Type #'.$m->type_id;
                    @endphp
                    <img class="km-fit-mod {{ $stateClass }}"
                         referrerpolicy="no-referrer"
                         style="left: {{ number_format($p['x'] - 16, 2, '.', '') }}px; top: {{ number_format($p['y'] - 16, 2, '.', '') }}px;"
                         src="https://images.evetech.net/types/{{ $m->type_id }}/icon?size=32"
                         title="{{ $modName }}{{ $destroyed ? ' · destroyed' : '' }}{{ $dropped ? ' · dropped' : '' }}"
                         alt="">
                @endif
            @endforeach
        @endforeach
    </div>

    <div style="min-width: 180px;">
        <div style="font-family:'JetBrains Mono',monospace;font-size:0.7rem;color:#7a7a82;text-transform:uppercase;letter-spacing:0.12em;margin-bottom:0.5rem;">Fitting</div>
        <div class="km-fit-legend">
            @foreach ($wheelLayout as $g)
                <span style="--tone: {{ $wheelGroupColor($g['key']) }};">{{ ucfirst($g['key']) }} · {{ count($wheelModulesByGroup[$g['key']] ?? []) }}/{{ $g['count'] }}</span>
            @endforeach
        </div>
        <div style="margin-top:0.9rem;font-size:0.65rem;color:#7a7a82;font-family:'JetBrains Mono',monospace;">
            <span style="color:#4ade80;">■</span> dropped
            <span style="color:#ff3838;margin-left:0.7rem;">■</span> destroyed
        </div>
    </div>
</div>
@endif

<div class="km-grid">
    {{-- Left column: Value breakdown + Attackers --}}
    <div style="display: flex; flex-direction: column; gap: 1.5rem;">

        {{-- Value breakdown --}}
        <div class="km-card">
            <h3>Value Breakdown @if(! $km->isEnriched()) <span class="km-badge km-badge-cyan" style="margin-left: 4px;">live estimate</span> @endif</h3>
            <div class="km-stat">
                <span class="km-stat-label">Hull</span>
                <span class="km-stat-value isk">{{ $formatIsk($hullValue) }}</span>
            </div>
            <div class="km-stat">
                <span class="km-stat-label">Fitted</span>
                <span class="km-stat-value isk">{{ $formatIsk($fittedValue) }}</span>
            </div>
            <div class="km-stat">
                <span class="km-stat-label">Cargo</span>
                <span class="km-stat-value isk">{{ $formatIsk($cargoValue) }}</span>
            </div>
            <div class="km-stat">
                <span class="km-stat-label">Drones</span>
                <span class="km-stat-value isk">{{ $formatIsk($droneValue) }}</span>
            </div>
            <div class="km-stat" style="margin-top: 0.25rem; border-top: 1px solid #26262b; padding-top: 0.5rem;">
                <span class="km-stat-label" style="font-weight: 700;">Total</span>
                <span class="km-stat-value total">{{ $formatIsk($totalValue) }} ISK</span>
            </div>
        </div>

        {{-- Combat info --}}
        <div class="km-card">
            <h3>Combat</h3>
            <div class="km-stat">
                <span class="km-stat-label">Attackers</span>
                <span class="km-stat-value">{{ $km->attacker_count }}</span>
            </div>
            <div class="km-stat">
                <span class="km-stat-label">Damage taken</span>
                <span class="km-stat-value">{{ number_format($km->victim_damage_taken) }}</span>
            </div>
            @if($km->is_solo_kill)
                <div class="km-stat">
                    <span class="km-stat-label">Type</span>
                    <span class="km-badge km-badge-cyan">Solo Kill</span>
                </div>
            @endif
            @if($km->is_npc_kill)
                <div class="km-stat">
                    <span class="km-stat-label">Type</span>
                    <span class="km-badge km-badge-red">NPC Kill</span>
                </div>
            @endif
        </div>

        {{-- Attackers --}}
        <div class="km-card">
            <h3>Attackers ({{ $km->attackers->count() }})</h3>
            @foreach($km->attackers->sortByDesc('damage_done') as $att)
                <div class="km-attacker @if($att->is_final_blow) km-final-blow @endif">
                    @if($att->character_id)
                        <img src="https://images.evetech.net/characters/{{ $att->character_id }}/portrait?size=64"
                             alt="" referrerpolicy="no-referrer" class="km-attacker-portrait">
                    @else
                        <img src="https://images.evetech.net/types/{{ $att->ship_type_id ?? 670 }}/icon?size=64"
                             alt="" referrerpolicy="no-referrer" class="km-attacker-portrait" style="border-radius: 4px;">
                    @endif
                    <div class="km-attacker-info">
                        <div class="km-attacker-name">
                            @if($att->character_id)
                                {{ $names[$att->character_id] ?? 'Pilot #'.$att->character_id }}{!! $roleBadge((int) $att->character_id) !!}
                            @elseif($att->faction_id)
                                {{ $names[$att->faction_id] ?? 'Faction #'.$att->faction_id }}
                            @else
                                NPC
                            @endif
                            @if($att->is_final_blow)
                                <span class="km-badge km-badge-red" style="margin-left: 4px;">Final blow</span>
                            @endif
                        </div>
                        <div class="km-attacker-corp">
                            @php
                                $etCorp = $eventTimeCorps[$att->character_id] ?? null;
                                $currentCorp = $names[$att->corporation_id] ?? '';
                            @endphp
                            @if($etCorp && $etCorp['corporation_id'] != $att->corporation_id && $etCorp['corporation_name'])
                                <span style="color: #e5a900;" title="Corporation at time of kill">{{ $etCorp['corporation_name'] }}</span>
                                <span style="font-size: 0.6rem; color: #3a3a42;"> (now: {{ $currentCorp }})</span>
                            @else
                                {{ $currentCorp }}
                            @endif
                            @php
                                $etCorpId = $etCorp['corporation_id'] ?? $att->corporation_id;
                                $etAlly = ($eventTimeAlliances[$etCorpId] ?? null);
                            @endphp
                            @if($etAlly && $etAlly['alliance_id'] != $att->alliance_id && $etAlly['alliance_name'])
                                / <span style="color: #e5a900;" title="Alliance at time of kill">{{ $etAlly['alliance_name'] }}</span>
                                @if($att->alliance_id && isset($names[$att->alliance_id]))
                                    <span style="font-size: 0.6rem; color: #3a3a42;"> (now: {{ $names[$att->alliance_id] }})</span>
                                @endif
                            @elseif($att->alliance_id && isset($names[$att->alliance_id]))
                                / {{ $names[$att->alliance_id] }}
                            @endif
                        </div>
                        @if($att->ship_type_id)
                            <div class="km-attacker-ship">
                                <img src="https://images.evetech.net/types/{{ $att->ship_type_id }}/icon?size=32"
                                     alt="" referrerpolicy="no-referrer" style="width: 16px; height: 16px; border-radius: 2px;">
                                {{ $typeNames[$att->ship_type_id] ?? 'Ship #'.$att->ship_type_id }}
                            </div>
                        @endif
                    </div>
                    <div class="km-attacker-damage">
                        {{ number_format($att->damage_done) }}
                        <div style="font-size: 0.6rem; color: #7a7a82;">dmg</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Right column: Items by slot --}}
    <div style="display: flex; flex-direction: column; gap: 1.5rem;">
        @foreach($slotOrder as $slot)
            @if(isset($itemsBySlot[$slot]) && $itemsBySlot[$slot]->isNotEmpty())
                <div class="km-card">
                    <h3>{{ $slotLabels[$slot] ?? ucfirst($slot) }}</h3>
                    @php
                        $slotItems = $itemsBySlot[$slot];
                        $fittedSlots = ['high','mid','low'];
                        $isFitted = in_array($slot, $fittedSlots);

                        if ($isFitted) {
                            $ordered = collect();
                            foreach ($slotItems->groupBy('flag')->sortKeys() as $flagItems) {
                                $modules = $flagItems->reject(fn ($i) => $chargeTypeIds->has($i->type_id));
                                $charges = $flagItems->filter(fn ($i) => $chargeTypeIds->has($i->type_id));
                                foreach ($modules as $m) { $ordered->push(['item' => $m, 'charge' => false]); }
                                foreach ($charges as $c) { $ordered->push(['item' => $c, 'charge' => true]); }
                            }
                        }
                    @endphp

                    @foreach($isFitted && isset($ordered) ? $ordered : $slotItems->sortByDesc('total_value')->map(fn ($i) => ['item' => $i, 'charge' => false]) as $entry)
                        @php $item = $entry['item']; $isCharge = $entry['charge']; @endphp
                        <div class="km-item-row" @if($isCharge) style="padding-left: 2rem; opacity: 0.7;" @endif>
                            <img src="https://images.evetech.net/types/{{ $item->type_id }}/icon?size=32"
                                 alt="" referrerpolicy="no-referrer"
                                 class="km-item-icon" @if($isCharge) style="width: 22px; height: 22px;" @endif>
                            <div class="km-item-name" title="{{ $item->type_name ?? $typeNames[$item->type_id] ?? 'Type #'.$item->type_id }}">
                                {{ $item->type_name ?? $typeNames[$item->type_id] ?? 'Type #'.$item->type_id }}
                            </div>
                            <div class="km-item-qty">
                                @if($item->quantity_destroyed > 0)
                                    <span class="km-item-destroyed">{{ $item->quantity_destroyed }}x</span>
                                @endif
                                @if($item->quantity_dropped > 0)
                                    <span class="km-item-dropped">{{ $item->quantity_dropped }}x</span>
                                @endif
                            </div>
                            <div class="km-item-value">
                                @php
                                    $displayValue = $item->total_value ? (float) $item->total_value : ($itemValues[$item->id] ?? null);
                                @endphp
                                @if($displayValue)
                                    {{ $formatIsk((float) $displayValue) }}
                                @else
                                    —
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        @endforeach
    </div>
</div>

<x-filament-panels::page>
@php
    $victim = [
        'name' => $names[$km->victim_character_id] ?? ($km->victim_ship_type_name ?? 'Unknown'),
        'corp' => $names[$km->victim_corporation_id] ?? null,
        'alliance' => $names[$km->victim_alliance_id] ?? null,
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
</style>

{{-- Victim header --}}
<div class="km-victim-header">
    <img src="https://images.evetech.net/types/{{ $km->victim_ship_type_id }}/render?size=128"
         alt="{{ $km->victim_ship_type_name }}" referrerpolicy="no-referrer"
         class="km-ship-render" width="96" height="96">
    <div>
        <div class="km-victim-name">{{ $victim['name'] }}</div>
        <div class="km-victim-meta" style="margin-top: 0.25rem;">
            {{ $km->victim_ship_type_name ?? $typeNames[$km->victim_ship_type_id] ?? 'Unknown Ship' }}
            <span style="color: #3a3a42; margin: 0 0.3rem;">&middot;</span>
            {{ $km->victim_ship_group_name ?? '' }}
        </div>
        <div class="km-victim-meta" style="margin-top: 0.35rem;">
            @if($km->victim_corporation_id)
                <img src="https://images.evetech.net/corporations/{{ $km->victim_corporation_id }}/logo?size=32"
                     alt="" referrerpolicy="no-referrer">
                {{ $victim['corp'] ?? 'Corp #'.$km->victim_corporation_id }}
            @endif
            @if($km->victim_alliance_id)
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
</div>

<div class="km-grid">
    {{-- Left column: Value breakdown + Attackers --}}
    <div style="display: flex; flex-direction: column; gap: 1.5rem;">

        {{-- Value breakdown --}}
        <div class="km-card">
            <h3>Value Breakdown</h3>
            <div class="km-stat">
                <span class="km-stat-label">Hull</span>
                <span class="km-stat-value isk">{{ $formatIsk((float) $km->hull_value) }}</span>
            </div>
            <div class="km-stat">
                <span class="km-stat-label">Fitted</span>
                <span class="km-stat-value isk">{{ $formatIsk((float) $km->fitted_value) }}</span>
            </div>
            <div class="km-stat">
                <span class="km-stat-label">Cargo</span>
                <span class="km-stat-value isk">{{ $formatIsk((float) $km->cargo_value) }}</span>
            </div>
            <div class="km-stat">
                <span class="km-stat-label">Drones</span>
                <span class="km-stat-value isk">{{ $formatIsk((float) $km->drone_value) }}</span>
            </div>
            <div class="km-stat" style="margin-top: 0.25rem; border-top: 1px solid #26262b; padding-top: 0.5rem;">
                <span class="km-stat-label" style="font-weight: 700;">Total</span>
                <span class="km-stat-value total">{{ $formatIsk((float) $km->total_value) }} ISK</span>
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
                            {{ $names[$att->character_id] ?? ($names[$att->faction_id] ?? 'NPC') }}
                            @if($att->is_final_blow)
                                <span class="km-badge km-badge-red" style="margin-left: 4px;">Final blow</span>
                            @endif
                        </div>
                        <div class="km-attacker-corp">
                            {{ $names[$att->corporation_id] ?? '' }}
                            @if($att->alliance_id && isset($names[$att->alliance_id]))
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

                        // For fitted slots (high/mid/low): group by flag so
                        // charges nest under their parent module. A module and
                        // its loaded charge share the same ESI flag value.
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
                                @if($item->total_value)
                                    {{ $formatIsk((float) $item->total_value) }}
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
</x-filament-panels::page>

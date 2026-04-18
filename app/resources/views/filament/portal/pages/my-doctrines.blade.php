<x-filament-panels::page>
    @if ($corp_id === null)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                No corporation detected on your linked EVE character. If your character recently joined a corp,
                the ESI affiliation sync runs periodically — check back later.
            </p>
        </div>
    @else
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center gap-3 mb-4">
                <img src="https://images.evetech.net/corporations/{{ $corp_id }}/logo?size=64"
                     class="w-10 h-10 rounded" alt="">
                <div>
                    <h2 class="text-lg font-semibold">{{ $corp_name }}</h2>
                    <p class="text-xs text-gray-500">
                        Doctrines your corp has been seen flying recently, tied to battlefield role.
                        Only fits we're <em>fairly certain</em> about are shown (confidence &ge; 0.70, observation floor reached).
                    </p>
                </div>
            </div>

            @if (count($doctrines) === 0)
                <p class="text-sm text-gray-500 italic">
                    No corp adoptions of active doctrines yet. Either your corp hasn't fielded a recognizable doctrine recently, or data accumulation is still below threshold.
                </p>
            @else
                @php
                    $byRole = collect($doctrines)->groupBy('role');
                    $roleOrder = ['fc', 'command', 'logi', 'mainline_dps', 'tackle', 'bomber'];
                    $roleLabel = fn ($r) => match ($r) {
                        'fc' => 'FC', 'logi' => 'Logistics', 'mainline_dps' => 'Mainline DPS',
                        'tackle' => 'Tackle', 'bomber' => 'Bombers', 'command' => 'Command', default => ucfirst($r),
                    };
                    $roleColor = fn ($r) => match ($r) {
                        'fc' => '#fde047',
                        'logi' => '#6ee7b7',
                        'mainline_dps' => '#93c5fd',
                        'tackle' => '#67e8f9',
                        'bomber' => '#fdba74',
                        'command' => '#f0abfc',
                        default => '#cbd5e1',
                    };
                    $confBand = function ($c) {
                        if ($c >= 0.95) return ['sure', '#22c55e'];
                        if ($c >= 0.85) return ['confident', '#84cc16'];
                        return ['likely', '#eab308'];
                    };
                @endphp
                @foreach ($roleOrder as $role)
                    @if (! $byRole->has($role)) @continue @endif
                    <div style="margin-bottom:1.25rem;">
                        <h3 style="color: {{ $roleColor($role) }}; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; font-size: 0.85rem; margin-bottom: 0.5rem;">
                            {{ $roleLabel($role) }}
                            <span style="color:#7a7a82; font-weight:400; letter-spacing:0; text-transform:none; font-size:0.8em;">· {{ $byRole->get($role)->count() }} doctrine(s)</span>
                        </h3>
                        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap:0.75rem;">
                            @foreach ($byRole->get($role) as $d)
                                @php [$bandName, $bandColor] = $confBand($d['confidence']); @endphp
                                <div style="border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.02); border-radius:6px; padding:0.75rem;">
                                    <div style="display:flex; gap:0.5rem; align-items:center; margin-bottom:0.5rem;">
                                        <img src="https://images.evetech.net/types/{{ $d['hull_type_id'] }}/icon?size=32"
                                             style="width:32px;height:32px;border-radius:3px;" alt="">
                                        <div style="flex:1; min-width:0;">
                                            <div style="font-weight:500; color:#e5e5e7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                {{ $d['hull_name'] }}
                                            </div>
                                            <div style="font-size:0.7rem; color:#7a7a82;">
                                                your corp: {{ $d['corp_n'] }}× · global: {{ $d['global_n'] }}× ·
                                                <span style="color: {{ $bandColor }};">{{ $bandName }}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <details>
                                        <summary style="font-size:0.75rem; color:#9ca3af; cursor:pointer;">
                                            {{ count($d['modules']) }} module(s){{ $d['has_corp_variant'] ?? false ? ' · corp variant tracked' : ' · no corp variant yet' }}
                                        </summary>
                                        <div style="margin-top:0.4rem;">
                                            @foreach ($d['modules'] as $m)
                                                @php
                                                    $both       = ! empty($m['global']) && ! empty($m['corp']);
                                                    $corpOnly   = empty($m['global']) && ! empty($m['corp']);
                                                    $globalOnly = ! empty($m['global']) && empty($m['corp']);
                                                    if ($both)            [$nameColor, $badge, $badgeColor] = ['#d1d5db', '✓', '#22c55e'];
                                                    elseif ($corpOnly)    [$nameColor, $badge, $badgeColor] = ['#fde047', 'corp', '#fde047'];
                                                    else                  [$nameColor, $badge, $badgeColor] = ['#9ca3af', 'global', '#93c5fd'];
                                                @endphp
                                                <div style="display:flex; gap:0.4rem; align-items:center; font-size:0.75rem; padding:0.15rem 0;">
                                                    <img src="https://images.evetech.net/types/{{ $m['type_id'] }}/icon?size=32"
                                                         style="width:16px;height:16px;border-radius:2px;" alt="">
                                                    <span style="flex:1; color: {{ $nameColor }}; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                        @if ($m['quantity'] > 1) {{ $m['quantity'] }}× @endif
                                                        {{ $m['name'] ?? ('type ' . $m['type_id']) }}
                                                    </span>
                                                    <span style="font-size:0.6em; padding:1px 4px; border-radius:6px; border:1px solid {{ $badgeColor }}; color: {{ $badgeColor }};">{{ $badge }}</span>
                                                    <span style="color:#7a7a82; font-size:0.7em;">{{ $m['slot'] }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    @endif
</x-filament-panels::page>

<x-filament-panels::page>
    @php
        $roleOrder = ['fc', 'command', 'logi', 'mainline_dps', 'tackle', 'bomber'];
        $roleLabel = fn ($r) => match ($r) {
            'fc' => 'FC', 'logi' => 'Logistics', 'mainline_dps' => 'Mainline DPS',
            'tackle' => 'Tackle', 'bomber' => 'Bombers', 'command' => 'Command', default => ucfirst($r),
        };
        $roleColor = fn ($r) => match ($r) {
            'fc' => '#fde047', 'logi' => '#6ee7b7', 'mainline_dps' => '#93c5fd',
            'tackle' => '#67e8f9', 'bomber' => '#fdba74', 'command' => '#f0abfc', default => '#cbd5e1',
        };
        $confBand = function ($c) {
            if ($c >= 0.95) return ['sure', '#22c55e'];
            if ($c >= 0.85) return ['confident', '#84cc16'];
            return ['likely', '#eab308'];
        };
    @endphp

    @if (! $corp_id && ! $alliance_id && ! $bloc_id)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                No corporation/alliance/bloc detected on your linked EVE character.
            </p>
        </div>
    @else
        @php
            $tabs = [
                'corp' => ['name' => $corp_name, 'id' => $corp_id, 'doctrines' => $corp_doctrines,
                    'logo' => $corp_id ? "https://images.evetech.net/corporations/{$corp_id}/logo?size=64" : null,
                    'label' => 'Corp'],
                'alliance' => ['name' => $alliance_name, 'id' => $alliance_id, 'doctrines' => $alliance_doctrines,
                    'logo' => $alliance_id ? "https://images.evetech.net/alliances/{$alliance_id}/logo?size=64" : null,
                    'label' => 'Alliance'],
                'bloc' => ['name' => $bloc_name, 'id' => $bloc_id, 'doctrines' => $bloc_doctrines,
                    'logo' => null, 'label' => 'Bloc'],
            ];
        @endphp
        @foreach ($tabs as $tabKey => $tab)
            @if (! $tab['id']) @continue @endif
            <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
                <div class="flex items-center gap-3 mb-3">
                    @if ($tab['logo'])
                        <img src="{{ $tab['logo'] }}" class="w-10 h-10 rounded" alt="">
                    @else
                        <div style="width:40px;height:40px;border-radius:4px;background:rgba(255,255,255,0.05);display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:0.7em;">BLOC</div>
                    @endif
                    <div>
                        <h2 class="text-lg font-semibold">{{ $tab['label'] }}: {{ $tab['name'] }}</h2>
                        <p class="text-xs text-gray-500">{{ count($tab['doctrines']) }} active doctrine(s) adopted</p>
                    </div>
                </div>

                @if (count($tab['doctrines']) === 0)
                    <p class="text-sm text-gray-500 italic">No active doctrines yet.</p>
                @else
                    @php $byRole = collect($tab['doctrines'])->groupBy('role'); @endphp
                    @foreach ($roleOrder as $role)
                        @if (! $byRole->has($role)) @continue @endif
                        <div style="margin-bottom:1rem;">
                            <h3 style="color: {{ $roleColor($role) }}; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; font-size: 0.8rem; margin-bottom: 0.4rem;">
                                {{ $roleLabel($role) }}
                                <span style="color:#7a7a82; font-weight:400; letter-spacing:0; text-transform:none; font-size:0.85em;">· {{ $byRole->get($role)->count() }}</span>
                            </h3>
                            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap:0.75rem;">
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
                                                    {{ $tab['label'] }}: {{ $d['scope_n'] }}× · global: {{ $d['global_n'] }}× ·
                                                    <span style="color: {{ $bandColor }};">{{ $bandName }}</span>
                                                </div>
                                            </div>
                                        </div>

                                        <details>
                                            <summary style="font-size:0.75rem; color:#9ca3af; cursor:pointer;">
                                                {{ count($d['modules']) }} module(s) · fit
                                            </summary>
                                            <div style="margin-top:0.4rem;">
                                                @foreach ($d['modules'] as $m)
                                                    @php
                                                        $both = ! empty($m['global']) && ! empty($m['corp']);
                                                        $corpOnly = empty($m['global']) && ! empty($m['corp']);
                                                        if ($both)        [$nc, $bd, $bc] = ['#d1d5db', '✓', '#22c55e'];
                                                        elseif ($corpOnly)[$nc, $bd, $bc] = ['#fde047', 'you', '#fde047'];
                                                        else              [$nc, $bd, $bc] = ['#9ca3af', 'global', '#93c5fd'];
                                                    @endphp
                                                    <div style="display:flex; gap:0.4rem; align-items:center; font-size:0.75rem; padding:0.15rem 0;">
                                                        <img src="https://images.evetech.net/types/{{ $m['type_id'] }}/icon?size=32"
                                                             style="width:16px;height:16px;border-radius:2px;" alt="">
                                                        <span style="flex:1; color: {{ $nc }}; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                            @if ($m['quantity'] > 1) {{ $m['quantity'] }}× @endif
                                                            {{ $m['name'] ?? ('type ' . $m['type_id']) }}
                                                        </span>
                                                        <span style="font-size:0.6em; padding:1px 4px; border-radius:6px; border:1px solid {{ $bc }}; color: {{ $bc }};">{{ $bd }}</span>
                                                        <span style="color:#7a7a82; font-size:0.7em;">{{ $m['slot'] }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </details>

                                        <details style="margin-top:0.35rem;">
                                            <summary style="font-size:0.75rem; color:#9ca3af; cursor:pointer;">EFT export (copy-paste into Pyfa / fitting window)</summary>
                                            <textarea readonly rows="14"
                                                      onclick="this.select();document.execCommand('copy');"
                                                      style="width:100%; font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size:0.72rem; background:#0b1218; color:#9eeaff; border:1px solid #13202a; border-radius:4px; padding:0.4rem; margin-top:0.3rem; resize:vertical;">{{ $d['eft'] }}</textarea>
                                        </details>

                                        <details style="margin-top:0.35rem;">
                                            <summary style="font-size:0.75rem; color:#9ca3af; cursor:pointer;">Buyall list</summary>
                                            <textarea readonly rows="{{ min(12, count($d['modules']) + 1) }}"
                                                      onclick="this.select();document.execCommand('copy');"
                                                      style="width:100%; font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size:0.72rem; background:#0b1218; color:#fde68a; border:1px solid #13202a; border-radius:4px; padding:0.4rem; margin-top:0.3rem; resize:vertical;">{{ $d['buyall'] }}</textarea>
                                        </details>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>
        @endforeach
    @endif
</x-filament-panels::page>

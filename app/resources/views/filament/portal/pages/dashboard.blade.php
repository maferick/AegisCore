<x-filament-panels::page>
    @if (empty($characters))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                No EVE character linked yet. Link one via <a href="/portal/account-settings" class="text-primary-500 underline">Account settings</a>.
            </p>
        </div>
    @endif

    @foreach ($characters as $c)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
            <div style="display:flex; gap:1rem; align-items:flex-start; margin-bottom:1rem;">
                <img src="https://images.evetech.net/characters/{{ $c['character_id'] }}/portrait?size=128"
                     referrerpolicy="no-referrer"
                     style="width:96px; height:96px; border-radius:8px; border:2px solid rgba(79,208,208,0.25); flex-shrink:0;" alt="">
                <div style="flex:1; min-width:0;">
                    <h2 class="text-xl font-semibold" style="margin-bottom:0.25rem;">{{ $c['character_name'] }}</h2>
                    <div style="display:flex; gap:0.75rem; align-items:center; font-size:0.85rem; color:#9ca3af; flex-wrap:wrap;">
                        @if ($c['corporation_id'])
                            <span style="display:inline-flex; gap:4px; align-items:center;">
                                <img src="https://images.evetech.net/corporations/{{ $c['corporation_id'] }}/logo?size=32"
                                     referrerpolicy="no-referrer" style="width:16px;height:16px;border-radius:3px;" alt="">
                                {{ $c['corporation_name'] ?? '#'.$c['corporation_id'] }}
                            </span>
                        @endif
                        @if ($c['alliance_id'])
                            <span style="display:inline-flex; gap:4px; align-items:center;">
                                <img src="https://images.evetech.net/alliances/{{ $c['alliance_id'] }}/logo?size=32"
                                     referrerpolicy="no-referrer" style="width:16px;height:16px;border-radius:3px;" alt="">
                                {{ $c['alliance_name'] ?? '#'.$c['alliance_id'] }}
                            </span>
                        @endif
                    </div>
                    <div style="display:flex; gap:1.25rem; font-size:0.78rem; color:#cbd5e1; margin-top:0.5rem;">
                        <span><span style="color:#4ade80;">{{ number_format($c['kills']) }}</span> kills</span>
                        <span><span style="color:#ff3838;">{{ number_format($c['losses']) }}</span> losses</span>
                    </div>
                </div>
            </div>

            @php
                // Equal-height history lists. Both sides share the same
                // max-height scroll container + identical row paddings
                // so the two columns render as a matched pair.
                $listStyle = 'display:flex; flex-direction:column; gap:0.35rem; max-height:240px; overflow-y:auto; padding-right:0.25rem;';
                $rowStyle  = 'display:flex; gap:0.5rem; align-items:center; padding:0.35rem 0; border-bottom:1px solid rgba(255,255,255,0.05); font-size:0.8rem;';
            @endphp
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.25rem; align-items:stretch;">
                {{-- Alliance history --}}
                <div>
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin-bottom:0.6rem;">Alliance history</h3>
                    @if (empty($c['alliances_timeline']))
                        <p style="font-size:0.78rem; color:#7a7a82; font-style:italic;">No prior alliance data cached yet.</p>
                    @else
                        <div style="{{ $listStyle }}">
                            @foreach ($c['alliances_timeline'] as $a)
                                <div style="{{ $rowStyle }}">
                                    <img src="https://images.evetech.net/alliances/{{ $a['alliance_id'] }}/logo?size=32"
                                         referrerpolicy="no-referrer"
                                         style="width:22px; height:22px; border-radius:3px; flex-shrink:0;" alt="">
                                    <div style="flex:1; min-width:0;">
                                        <div style="color:#e5e5e7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                            {{ $a['alliance_name'] }}
                                        </div>
                                        <div style="font-size:0.66rem; color:#7a7a82;">
                                            joined {{ \Carbon\Carbon::parse($a['first_seen'])->format('Y-m-d') }}
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Corp history --}}
                <div>
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin-bottom:0.6rem;">Corporation history</h3>
                    @if (empty($c['corp_timeline']))
                        <p style="font-size:0.78rem; color:#7a7a82; font-style:italic;">No corp history cached yet.</p>
                    @else
                        <div style="{{ $listStyle }}">
                            @foreach ($c['corp_timeline'] as $row)
                                <div style="{{ $rowStyle }}">
                                    <img src="https://images.evetech.net/corporations/{{ $row['corp_id'] }}/logo?size=32"
                                         referrerpolicy="no-referrer"
                                         style="width:22px; height:22px; border-radius:3px; flex-shrink:0;" alt="">
                                    <div style="flex:1; min-width:0;">
                                        <div style="color:#e5e5e7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                            {{ $row['corp_name'] }}
                                            @if (! empty($row['alliance_name']))
                                                <span style="color:#7a7a82; font-size:0.85em;">/ {{ $row['alliance_name'] }}</span>
                                            @endif
                                        </div>
                                        <div style="font-size:0.66rem; color:#7a7a82;">
                                            {{ \Carbon\Carbon::parse($row['start_date'])->format('Y-m-d') }}
                                            →
                                            {{ $row['end_date'] ? \Carbon\Carbon::parse($row['end_date'])->format('Y-m-d') : 'present' }}
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- Top hulls --}}
            @if (! empty($c['top_hulls']))
                <div style="margin-top:1rem;">
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin-bottom:0.5rem;">Top hulls flown</h3>
                    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
                        @foreach ($c['top_hulls'] as $h)
                            <span style="display:inline-flex; align-items:center; gap:0.4rem; font-size:0.8rem;">
                                <img src="https://images.evetech.net/types/{{ $h['type_id'] }}/icon?size=32"
                                     referrerpolicy="no-referrer" style="width:22px;height:22px;border-radius:3px;" alt="">
                                {{ $h['name'] }}
                                <span style="color:#7a7a82; font-size:0.85em;">×{{ number_format($h['n']) }}</span>
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endforeach
</x-filament-panels::page>

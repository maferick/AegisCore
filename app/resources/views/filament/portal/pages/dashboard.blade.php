<x-filament-panels::page>
    @php
        $fmtIsk = function (float $v): string {
            if ($v >= 1e12) return number_format($v / 1e12, 2) . ' T';
            if ($v >= 1e9)  return number_format($v / 1e9, 2) . ' B';
            if ($v >= 1e6)  return number_format($v / 1e6, 2) . ' M';
            if ($v >= 1e3)  return number_format($v / 1e3, 1) . ' K';
            return number_format($v, 0);
        };
    @endphp

    @if (! empty($data_since))
        <div style="font-size:0.7rem; color:#7a7a82; margin-bottom:0.75rem; font-style:italic;">
            Kill + ISK stats cover killmails since
            <span style="color:#cbd5e1;">{{ \Carbon\Carbon::parse($data_since)->format('Y-m-d') }}</span>
            (our data floor). Earlier activity not counted.
        </div>
    @endif

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

            {{-- Highlights strip --}}
            @php $h = $c['highlights'] ?? []; @endphp
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:0.75rem; margin-bottom:1rem;">
                <div style="background:rgba(34,197,94,0.08); border:1px solid rgba(34,197,94,0.2); border-radius:6px; padding:0.6rem 0.8rem;" title="Full value of every killmail you appear on (zKill convention). Differs from battle-report side totals — those count each killmail once per side; this counts it once per pilot.">
                    <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">ISK destroyed</div>
                    <div style="font-size:1.1rem; font-weight:600; color:#4ade80; margin-top:0.15rem;">
                        {{ $fmtIsk($h['isk_destroyed'] ?? 0) }}
                    </div>
                    <div style="font-size:0.6rem; color:#7a7a82; margin-top:0.15rem;">full KM value, every kill you're on</div>
                </div>
                <div style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.2); border-radius:6px; padding:0.6rem 0.8rem;">
                    <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">ISK lost</div>
                    <div style="font-size:1.1rem; font-weight:600; color:#ff6b6b; margin-top:0.15rem;">
                        {{ $fmtIsk($h['isk_lost'] ?? 0) }}
                    </div>
                </div>
                @if (! empty($h['biggest_kill']))
                    <a href="/portal/killmails/{{ $h['biggest_kill']['killmail_id'] }}" style="text-decoration:none; color:inherit;">
                        <div style="background:rgba(34,197,94,0.08); border:1px solid rgba(34,197,94,0.2); border-radius:6px; padding:0.6rem 0.8rem; display:flex; gap:0.6rem; align-items:center;">
                            <img src="https://images.evetech.net/types/{{ $h['biggest_kill']['ship_id'] }}/icon?size=32"
                                 referrerpolicy="no-referrer" style="width:32px; height:32px; border-radius:4px; flex-shrink:0;" alt="">
                            <div style="flex:1; min-width:0;">
                                <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Biggest kill</div>
                                <div style="font-size:0.9rem; font-weight:600; color:#4ade80; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                    {{ $fmtIsk($h['biggest_kill']['isk']) }}
                                </div>
                                <div style="font-size:0.65rem; color:#9ca3af; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                    {{ $h['biggest_kill']['ship_name'] }}
                                </div>
                            </div>
                        </div>
                    </a>
                @endif
                @if (! empty($h['biggest_loss']))
                    <a href="/portal/killmails/{{ $h['biggest_loss']['killmail_id'] }}" style="text-decoration:none; color:inherit;">
                        <div style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.2); border-radius:6px; padding:0.6rem 0.8rem; display:flex; gap:0.6rem; align-items:center;">
                            <img src="https://images.evetech.net/types/{{ $h['biggest_loss']['ship_id'] }}/icon?size=32"
                                 referrerpolicy="no-referrer" style="width:32px; height:32px; border-radius:4px; flex-shrink:0;" alt="">
                            <div style="flex:1; min-width:0;">
                                <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Biggest loss</div>
                                <div style="font-size:0.9rem; font-weight:600; color:#ff6b6b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                    {{ $fmtIsk($h['biggest_loss']['isk']) }}
                                </div>
                                <div style="font-size:0.65rem; color:#9ca3af; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                    {{ $h['biggest_loss']['ship_name'] }}
                                </div>
                            </div>
                        </div>
                    </a>
                @endif
                <div style="background:rgba(148,163,184,0.08); border:1px solid rgba(148,163,184,0.2); border-radius:6px; padding:0.6rem 0.8rem;">
                    <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Solo kills</div>
                    <div style="font-size:1.1rem; font-weight:600; color:#e5e5e7; margin-top:0.15rem;">
                        {{ number_format($h['solo_kills'] ?? 0) }}
                    </div>
                </div>
                @if (! empty($h['largest_gang']))
                    <div style="background:rgba(148,163,184,0.08); border:1px solid rgba(148,163,184,0.2); border-radius:6px; padding:0.6rem 0.8rem;">
                        <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Largest gang kill</div>
                        <div style="font-size:1.1rem; font-weight:600; color:#e5e5e7; margin-top:0.15rem;">
                            {{ number_format($h['largest_gang']) }} pilots
                        </div>
                    </div>
                @endif
            </div>

            @php
                // Equal-sized history lists. Scaled up from the earlier
                // mismatch: 28px avatars + roomier padding + 360px
                // scroll container. Both columns share these values
                // so they render as a matched pair.
                $listStyle = 'display:flex; flex-direction:column; gap:0.5rem; max-height:360px; overflow-y:auto; padding-right:0.25rem;';
                $rowStyle  = 'display:flex; gap:0.6rem; align-items:center; padding:0.45rem 0; border-bottom:1px solid rgba(255,255,255,0.05); font-size:0.85rem;';
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
                                         style="width:28px; height:28px; border-radius:4px; flex-shrink:0;" alt="">
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
                                         style="width:28px; height:28px; border-radius:4px; flex-shrink:0;" alt="">
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

            {{-- Role breakdown + battles participated strip --}}
            <div style="display:grid; grid-template-columns: 2fr 1fr; gap:1.25rem; margin-top:1.25rem;">
                <div>
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin-bottom:0.6rem;">Role breakdown</h3>
                    @if (empty($c['role_breakdown']))
                        <p style="font-size:0.78rem; color:#7a7a82; font-style:italic;">No role-tagged kills yet.</p>
                    @else
                        @php
                            $roleColor = [
                                'fc' => '#fde047', 'logi' => '#6ee7b7', 'bomber' => '#fdba74',
                                'command' => '#f0abfc', 'tackle' => '#67e8f9', 'mainline_dps' => '#93c5fd',
                            ];
                            $roleLabel = [
                                'fc' => 'FC', 'logi' => 'Logi', 'bomber' => 'Bomber',
                                'command' => 'Cmd', 'tackle' => 'Tackle', 'mainline_dps' => 'DPS',
                            ];
                        @endphp
                        {{-- Stacked bar --}}
                        <div style="display:flex; height:22px; border-radius:4px; overflow:hidden; margin-bottom:0.5rem;">
                            @foreach ($c['role_breakdown'] as $r)
                                @php $pct = $c['role_total'] > 0 ? ($r['n'] / $c['role_total'] * 100) : 0; @endphp
                                <div title="{{ $roleLabel[$r['role']] ?? $r['role'] }}: {{ number_format($r['n']) }} ({{ round($pct, 1) }}%)"
                                     style="flex:{{ $r['n'] }}; background:{{ $roleColor[$r['role']] ?? '#64748b' }}; opacity:0.85;"></div>
                            @endforeach
                        </div>
                        <div style="display:flex; gap:0.75rem; flex-wrap:wrap; font-size:0.75rem;">
                            @foreach ($c['role_breakdown'] as $r)
                                @php $pct = $c['role_total'] > 0 ? ($r['n'] / $c['role_total'] * 100) : 0; @endphp
                                <span style="display:inline-flex; gap:0.35rem; align-items:center;">
                                    <span style="width:10px; height:10px; border-radius:2px; background:{{ $roleColor[$r['role']] ?? '#64748b' }};"></span>
                                    <span style="color:#cbd5e1;">{{ $roleLabel[$r['role']] ?? $r['role'] }}</span>
                                    <span style="color:#7a7a82;">{{ round($pct, 1) }}%</span>
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div style="background:rgba(148,163,184,0.08); border:1px solid rgba(148,163,184,0.2); border-radius:6px; padding:0.6rem 0.8rem;">
                    <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Battles participated</div>
                    <div style="font-size:1.4rem; font-weight:700; color:#e5e5e7; margin-top:0.1rem;">
                        {{ number_format($c['battles_participated'] ?? 0) }}
                    </div>
                    <div style="font-size:0.68rem; color:#7a7a82; margin-top:0.15rem;">distinct theaters in our data</div>
                </div>
            </div>

            {{-- Top systems + flew with / fought against --}}
            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:1.25rem; margin-top:1.25rem;">
                <div>
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin-bottom:0.6rem;">Top systems</h3>
                    @if (empty($c['top_systems']))
                        <p style="font-size:0.78rem; color:#7a7a82; font-style:italic;">—</p>
                    @else
                        <div style="display:flex; flex-direction:column; gap:0.3rem;">
                            @foreach ($c['top_systems'] as $s)
                                <div style="display:flex; justify-content:space-between; font-size:0.82rem;">
                                    <span style="color:#e5e5e7;">{{ $s['name'] }}</span>
                                    <span style="color:#9ca3af;">{{ number_format($s['n']) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div>
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin-bottom:0.6rem;">Flew with</h3>
                    @if (empty($c['fought_with']))
                        <p style="font-size:0.78rem; color:#7a7a82; font-style:italic;">—</p>
                    @else
                        <div style="display:flex; flex-direction:column; gap:0.4rem;">
                            @foreach ($c['fought_with'] as $a)
                                <div style="display:flex; gap:0.4rem; align-items:center; font-size:0.82rem;">
                                    <img src="https://images.evetech.net/alliances/{{ $a['alliance_id'] }}/logo?size=32"
                                         referrerpolicy="no-referrer" style="width:20px; height:20px; border-radius:3px; flex-shrink:0;" alt="">
                                    <span style="flex:1; color:#e5e5e7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $a['name'] }}</span>
                                    <span style="color:#9ca3af;">{{ number_format($a['n']) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div>
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin-bottom:0.6rem;">Fought against</h3>
                    @if (empty($c['fought_against']))
                        <p style="font-size:0.78rem; color:#7a7a82; font-style:italic;">—</p>
                    @else
                        <div style="display:flex; flex-direction:column; gap:0.4rem;">
                            @foreach ($c['fought_against'] as $a)
                                <div style="display:flex; gap:0.4rem; align-items:center; font-size:0.82rem;">
                                    <img src="https://images.evetech.net/alliances/{{ $a['alliance_id'] }}/logo?size=32"
                                         referrerpolicy="no-referrer" style="width:20px; height:20px; border-radius:3px; flex-shrink:0;" alt="">
                                    <span style="flex:1; color:#e5e5e7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $a['name'] }}</span>
                                    <span style="color:#9ca3af;">{{ number_format($a['n']) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- Top hulls --}}
            @if (! empty($c['top_hulls']))
                <div style="margin-top:1.25rem;">
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin-bottom:0.6rem;">Top hulls flown</h3>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:0.75rem;">
                        @foreach ($c['top_hulls'] as $h)
                            <div style="display:flex; gap:0.75rem; align-items:center; padding:0.7rem 0.9rem; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06); border-radius:6px;">
                                <img src="https://images.evetech.net/types/{{ $h['type_id'] }}/icon?size=64"
                                     referrerpolicy="no-referrer"
                                     style="width:48px; height:48px; border-radius:6px; flex-shrink:0;" alt="">
                                <div style="flex:1; min-width:0;">
                                    <div style="font-size:0.95rem; font-weight:600; color:#e5e5e7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                        {{ $h['name'] }}
                                    </div>
                                    <div style="font-size:0.78rem; color:#9ca3af; margin-top:0.1rem;">
                                        ×<span style="color:#4fd0d0; font-weight:600;">{{ number_format($h['n']) }}</span>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endforeach
</x-filament-panels::page>

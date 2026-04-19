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
                    <a href="/portal/killmails/{{ $h['biggest_kill']['killmail_id'] }}" style="text-decoration:none; color:inherit; display:block; height:100%;">
                        <div style="background:rgba(34,197,94,0.08); border:1px solid rgba(34,197,94,0.2); border-radius:6px; padding:0.6rem 0.8rem; display:flex; gap:0.6rem; align-items:center; height:100%; box-sizing:border-box;">
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
                    <a href="/portal/killmails/{{ $h['biggest_loss']['killmail_id'] }}" style="text-decoration:none; color:inherit; display:block; height:100%;">
                        <div style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.2); border-radius:6px; padding:0.6rem 0.8rem; display:flex; gap:0.6rem; align-items:center; height:100%; box-sizing:border-box;">
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
                @if (! empty($h['isk_efficiency']))
                    <div style="background:rgba(79,208,208,0.08); border:1px solid rgba(79,208,208,0.25); border-radius:6px; padding:0.6rem 0.8rem;" title="ISK destroyed / (ISK destroyed + ISK lost) — zKill convention.">
                        <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">ISK efficiency</div>
                        <div style="font-size:1.1rem; font-weight:600; color:#4fd0d0; margin-top:0.15rem;">
                            {{ $h['isk_efficiency'] }}%
                        </div>
                    </div>
                @endif
                <div style="background:rgba(148,163,184,0.08); border:1px solid rgba(148,163,184,0.2); border-radius:6px; padding:0.6rem 0.8rem;">
                    <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Final blows</div>
                    <div style="font-size:1.1rem; font-weight:600; color:#e5e5e7; margin-top:0.15rem;">
                        {{ number_format($h['final_blows'] ?? 0) }}
                    </div>
                </div>
                @if (($h['pod_losses'] ?? 0) > 0)
                    <div style="background:rgba(239,68,68,0.05); border:1px solid rgba(239,68,68,0.15); border-radius:6px; padding:0.6rem 0.8rem;">
                        <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Pods lost</div>
                        <div style="font-size:1.1rem; font-weight:600; color:#fca5a5; margin-top:0.15rem;">
                            {{ number_format($h['pod_losses']) }}
                        </div>
                    </div>
                @endif
                @if (($h['capital_kills'] ?? 0) > 0)
                    <div style="background:rgba(168,85,247,0.1); border:1px solid rgba(168,85,247,0.25); border-radius:6px; padding:0.6rem 0.8rem;" title="Killmails where the victim was a capital-class hull (Dreadnought / Carrier / Supercarrier / Titan / FAX / Rorqual).">
                        <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Capital kills</div>
                        <div style="font-size:1.1rem; font-weight:600; color:#f0abfc; margin-top:0.15rem;">
                            {{ number_format($h['capital_kills']) }}
                        </div>
                    </div>
                @endif
                @if (! empty($h['first_km']) && ! empty($h['last_km']))
                    <div style="background:rgba(148,163,184,0.08); border:1px solid rgba(148,163,184,0.2); border-radius:6px; padding:0.6rem 0.8rem;">
                        <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Active span</div>
                        <div style="font-size:0.82rem; font-weight:600; color:#e5e5e7; margin-top:0.15rem;">
                            {{ \Carbon\Carbon::parse($h['first_km'])->format('Y-m-d') }} → {{ \Carbon\Carbon::parse($h['last_km'])->format('Y-m-d') }}
                        </div>
                        <div style="font-size:0.6rem; color:#7a7a82; margin-top:0.15rem;">
                            last: {{ \Carbon\Carbon::parse($h['last_km'])->diffForHumans() }}
                        </div>
                    </div>
                @endif
            </div>

            @php $hh = $c['hour_histogram'] ?? []; $hhMax = ! empty($hh) ? max($hh) : 0; @endphp
            @if ($hhMax > 0)
                <div style="margin-bottom:1rem;">
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin-bottom:0.4rem;">Active hours (UTC)</h3>
                    <div style="display:grid; grid-template-columns: repeat(24, 1fr); gap:2px; align-items:end; height:48px;">
                        @for ($hr = 0; $hr < 24; $hr++)
                            @php $v = $hh[$hr] ?? 0; $pct = $hhMax > 0 ? round($v / $hhMax * 100) : 0; @endphp
                            <div title="{{ sprintf('%02d:00 UTC — %d kills', $hr, $v) }}"
                                 style="background:{{ $v > 0 ? 'rgba(79,208,208,0.5)' : 'rgba(148,163,184,0.1)' }};
                                        height:{{ max(2, $pct) }}%;
                                        border-radius:2px 2px 0 0;"></div>
                        @endfor
                    </div>
                    <div style="display:grid; grid-template-columns: repeat(24, 1fr); gap:2px; margin-top:3px;">
                        @for ($hr = 0; $hr < 24; $hr++)
                            <div style="font-size:0.55rem; color:#7a7a82; text-align:center;">{{ $hr % 3 === 0 ? sprintf('%02d', $hr) : '' }}</div>
                        @endfor
                    </div>
                </div>
            @endif

            @php
                // Show last 6 entries of each so both columns fit on
                // screen without scroll. Shared style for matched
                // visual rhythm.
                $recentAlliances = array_slice($c['alliances_timeline'], 0, 6);
                $recentCorps = array_slice($c['corp_timeline'], 0, 6);
                $listStyle = 'display:flex; flex-direction:column; gap:0.5rem;';
                $rowStyle  = 'display:flex; gap:0.6rem; align-items:center; padding:0.45rem 0; border-bottom:1px solid rgba(255,255,255,0.05); font-size:0.85rem;';
            @endphp
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.25rem; align-items:stretch;">
                {{-- Alliance history --}}
                <div>
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin-bottom:0.6rem;">Alliance history</h3>
                    @if (empty($recentAlliances))
                        <p style="font-size:0.78rem; color:#7a7a82; font-style:italic;">No prior alliance data cached yet.</p>
                    @else
                        <div style="{{ $listStyle }}">
                            @foreach ($recentAlliances as $a)
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
                        @if (count($c['alliances_timeline']) > 6)
                            <div style="font-size:0.68rem; color:#7a7a82; margin-top:0.4rem; font-style:italic;">
                                +{{ count($c['alliances_timeline']) - 6 }} older
                            </div>
                        @endif
                    @endif
                </div>

                {{-- Corp history --}}
                <div>
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin-bottom:0.6rem;">Corporation history</h3>
                    @if (empty($recentCorps))
                        <p style="font-size:0.78rem; color:#7a7a82; font-style:italic;">No corp history cached yet.</p>
                    @else
                        <div style="{{ $listStyle }}">
                            @foreach ($recentCorps as $row)
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
                        @if (count($c['corp_timeline']) > 6)
                            <div style="font-size:0.68rem; color:#7a7a82; margin-top:0.4rem; font-style:italic;">
                                +{{ count($c['corp_timeline']) - 6 }} older
                            </div>
                        @endif
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

            {{-- Graph insights (from Neo4j counter-intel projection) --}}
            @php
                $fc = $c['flight_crew'] ?? [];
                $ae = $c['arch_enemies'] ?? [];
                $sr = $c['structural_rank'] ?? null;
                $showGraph = ! empty($fc) || ! empty($ae) || $sr !== null;
            @endphp
            @if ($showGraph)
                <div style="margin-top:1.5rem; padding-top:1rem; border-top:1px solid rgba(255,255,255,0.06);">
                    <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin-bottom:0.75rem;">
                        Graph insights
                        <span style="font-size:0.6rem; color:#7a7a82; text-transform:none; letter-spacing:0.03em; font-weight:400; font-style:italic;">
                            — from counter-intel co-fighting graph (rolling 90d)
                        </span>
                    </h3>
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:1.25rem;">
                        {{-- Flight crew --}}
                        <div>
                            <div style="font-size:0.68rem; text-transform:uppercase; letter-spacing:0.08em; color:#86efac; margin-bottom:0.5rem;">Flight crew</div>
                            @if (empty($fc))
                                <p style="font-size:0.75rem; color:#7a7a82; font-style:italic;">No data yet.</p>
                            @else
                                <div style="display:flex; flex-direction:column; gap:0.35rem;">
                                    @foreach ($fc as $p)
                                        <div style="display:flex; gap:0.4rem; align-items:center; font-size:0.78rem;">
                                            <img src="https://images.evetech.net/characters/{{ $p['character_id'] }}/portrait?size=32"
                                                 referrerpolicy="no-referrer" style="width:20px;height:20px;border-radius:50%;" alt="">
                                            <div style="flex:1; min-width:0;">
                                                <div style="color:#e5e5e7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                    {{ $p['name'] ?? ('Pilot #'.$p['character_id']) }}
                                                </div>
                                                @if ($p['alliance_name'])
                                                    <div style="font-size:0.62rem; color:#7a7a82; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $p['alliance_name'] }}</div>
                                                @endif
                                            </div>
                                            <span style="color:#86efac; font-size:0.7rem;" title="{{ $p['distinct_interactions'] }} distinct sessions · {{ number_format($p['total_weight'], 1) }} weighted">
                                                {{ $p['distinct_interactions'] }}×
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        {{-- Arch-enemies --}}
                        <div>
                            <div style="font-size:0.68rem; text-transform:uppercase; letter-spacing:0.08em; color:#fca5a5; margin-bottom:0.5rem;">Arch-enemies</div>
                            @if (empty($ae))
                                <p style="font-size:0.75rem; color:#7a7a82; font-style:italic;">No data yet.</p>
                            @else
                                <div style="display:flex; flex-direction:column; gap:0.35rem;">
                                    @foreach ($ae as $p)
                                        <div style="display:flex; gap:0.4rem; align-items:center; font-size:0.78rem;">
                                            <img src="https://images.evetech.net/characters/{{ $p['character_id'] }}/portrait?size=32"
                                                 referrerpolicy="no-referrer" style="width:20px;height:20px;border-radius:50%;" alt="">
                                            <div style="flex:1; min-width:0;">
                                                <div style="color:#e5e5e7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                    {{ $p['name'] ?? ('Pilot #'.$p['character_id']) }}
                                                </div>
                                                @if ($p['alliance_name'])
                                                    <div style="font-size:0.62rem; color:#7a7a82; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $p['alliance_name'] }}</div>
                                                @endif
                                            </div>
                                            <span style="color:#fca5a5; font-size:0.7rem;" title="{{ $p['distinct_interactions'] }} distinct sessions · {{ number_format($p['total_weight'], 1) }} weighted">
                                                {{ $p['distinct_interactions'] }}×
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        {{-- Structural rank --}}
                        <div>
                            <div style="font-size:0.68rem; text-transform:uppercase; letter-spacing:0.08em; color:#a5b4fc; margin-bottom:0.5rem;">Your place in the graph</div>
                            @if ($sr === null)
                                <p style="font-size:0.75rem; color:#7a7a82; font-style:italic;">Not enough history yet for graph scoring.</p>
                            @else
                                <div style="display:flex; flex-direction:column; gap:0.75rem; font-size:0.78rem;">
                                    <div title="Measures how connected you are to other active pilots. High = you fly with lots of pilots who ALSO fly with lots of pilots (the fleet regulars). Low = you fly with only a few people. Same math Google uses to rank web pages.">
                                        <div style="display:flex; justify-content:space-between;">
                                            <span style="color:#9ca3af;">Fleet connectedness</span>
                                            <span style="color:#a5b4fc;">top {{ max(1, (int) ceil(100 - $sr['pagerank_pct'])) }}%</span>
                                        </div>
                                        <div style="background:rgba(99,102,241,0.08); height:4px; border-radius:2px; margin-top:3px; overflow:hidden;">
                                            <div style="width:{{ $sr['pagerank_pct'] }}%; height:100%; background:rgba(165,180,252,0.6);"></div>
                                        </div>
                                        <div style="font-size:0.62rem; color:#7a7a82; margin-top:2px;">
                                            how often you fly with pilots who themselves fly with many others
                                        </div>
                                    </div>
                                    <div title="Measures how often you sit between otherwise-separate fleet groups. High = you're a connector — pilots from different crews both end up on killmails with you. Low = you stick to one crew.">
                                        <div style="display:flex; justify-content:space-between;">
                                            <span style="color:#9ca3af;">Cross-crew bridge</span>
                                            <span style="color:#a5b4fc;">top {{ max(1, (int) ceil(100 - $sr['betweenness_pct'])) }}%</span>
                                        </div>
                                        <div style="background:rgba(99,102,241,0.08); height:4px; border-radius:2px; margin-top:3px; overflow:hidden;">
                                            <div style="width:{{ $sr['betweenness_pct'] }}%; height:100%; background:rgba(165,180,252,0.6);"></div>
                                        </div>
                                        <div style="font-size:0.62rem; color:#7a7a82; margin-top:2px;">
                                            how often you connect otherwise-separate fleet groups
                                        </div>
                                    </div>
                                    <div style="font-size:0.6rem; color:#7a7a82; font-style:italic;">
                                        compared to {{ number_format($sr['cohort_size']) }} pilots with enough flight history
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif


            {{-- Activity map — lazy-loaded via
                 /portal/characters/{cid}/activity-map so the rest of
                 the card renders fast. --}}
            <div style="margin-top:1.5rem; padding-top:1rem; border-top:1px solid rgba(255,255,255,0.06);">
                <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin-bottom:0.4rem;">
                    Where you've been · last 30 days
                    <span style="font-size:0.6rem; color:#7a7a82; text-transform:none; letter-spacing:0.03em; font-weight:400; font-style:italic;">
                        — loads after the page
                    </span>
                </h3>
                <div data-activity-map-for="{{ $c['character_id'] }}"
                     style="min-height:520px; background:#0b0e14; border:1px solid rgba(255,255,255,0.06); border-radius:6px; display:flex; align-items:center; justify-content:center; color:#7a7a82; font-size:0.78rem;">
                    Loading map…
                </div>
                <script>
                    (function () {
                        var slot = document.querySelector('[data-activity-map-for="{{ $c['character_id'] }}"]');
                        if (!slot || slot.dataset.loaded) return;
                        slot.dataset.loaded = '1';
                        fetch('/portal/characters/{{ $c['character_id'] }}/activity-map', {
                            credentials: 'same-origin',
                            headers: { 'Accept': 'text/html' },
                        })
                        .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
                        .then(function (html) { slot.innerHTML = html; })
                        .catch(function (e) {
                            slot.innerHTML = '<div style="padding:0.6rem; color:#ff6b6b; font-size:0.78rem;">Failed to load map (' + e + ')</div>';
                        });
                    })();
                </script>
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

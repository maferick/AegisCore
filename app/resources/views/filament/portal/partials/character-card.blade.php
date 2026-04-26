        @php
            if (! isset($fmtIsk)) {
                $fmtIsk = function (float $v): string {
                    if ($v >= 1e12) return number_format($v / 1e12, 2) . ' T';
                    if ($v >= 1e9)  return number_format($v / 1e9, 2) . ' B';
                    if ($v >= 1e6)  return number_format($v / 1e6, 2) . ' M';
                    if ($v >= 1e3)  return number_format($v / 1e3, 1) . ' K';
                    return number_format($v, 0);
                };
            }
        @endphp
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
                    <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;" title="Consecutive kill windows separated by 60+ min gaps — one session ≈ one fleet op.">Fleet sessions</div>
                    <div style="font-size:1.4rem; font-weight:700; color:#e5e5e7; margin-top:0.1rem;">
                        {{ number_format($c['battles_participated'] ?? 0) }}
                    </div>
                    <div style="font-size:0.68rem; color:#7a7a82; margin-top:0.15rem;">killmails grouped by 1h gaps</div>
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
                                        @php $rel = $p['current_relationship'] ?? 'unlabeled'; @endphp
                                        <div style="display:flex; gap:0.4rem; align-items:center; font-size:0.78rem;">
                                            <img src="https://images.evetech.net/characters/{{ $p['character_id'] }}/portrait?size=32"
                                                 referrerpolicy="no-referrer" style="width:20px;height:20px;border-radius:50%;" alt="">
                                            <div style="flex:1; min-width:0;">
                                                <div style="color:#e5e5e7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                    {{ $p['name'] ?? ('Pilot #'.$p['character_id']) }}
                                                    @if ($rel === 'hostile_bloc')
                                                        <span title="Flew with you historically; their current alliance is in a hostile bloc now" style="color:#fca5a5;font-size:0.6rem;padding:0 4px;border:1px solid rgba(239,68,68,0.4);border-radius:8px;margin-left:3px;">⚠ hostile now</span>
                                                    @endif
                                                </div>
                                                @if ($p['alliance_name'])
                                                    <div style="font-size:0.62rem; color:{{ $rel === 'hostile_bloc' ? '#fca5a5' : '#7a7a82' }}; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $p['alliance_name'] }}</div>
                                                @endif
                                            </div>
                                            @php $fcN = (int) ($p['event_count'] ?? $p['distinct_interactions'] ?? 0); @endphp
                                            <span style="color:#86efac; font-size:0.7rem;" title="{{ $fcN }} shared killmails · {{ $p['distinct_interactions'] ?? 0 }} sessions">
                                                {{ $fcN }}×
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
                                        @php $rel = $p['current_relationship'] ?? 'unlabeled'; @endphp
                                        <div style="display:flex; gap:0.4rem; align-items:center; font-size:0.78rem;">
                                            <img src="https://images.evetech.net/characters/{{ $p['character_id'] }}/portrait?size=32"
                                                 referrerpolicy="no-referrer" style="width:20px;height:20px;border-radius:50%;" alt="">
                                            <div style="flex:1; min-width:0;">
                                                <div style="color:#e5e5e7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                    {{ $p['name'] ?? ('Pilot #'.$p['character_id']) }}
                                                    @if ($rel === 'same_bloc')
                                                        <span title="Fought historically; their current alliance is now in YOUR bloc" style="color:#86efac;font-size:0.6rem;padding:0 4px;border:1px solid rgba(34,197,94,0.4);border-radius:8px;margin-left:3px;">✓ now ally</span>
                                                    @endif
                                                </div>
                                                @if ($p['alliance_name'])
                                                    <div style="font-size:0.62rem; color:{{ $rel === 'same_bloc' ? '#86efac' : '#7a7a82' }}; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $p['alliance_name'] }}</div>
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


            {{-- Counter-Intel section — dedicated review block.
                 Hidden when the operator has no resolvable bloc (no
                 ESI alliance / no bloc tag) or the dossier service
                 returned not_found. Lexicon constraint enforced by
                 the service: never "spy" / "infiltrator" — review
                 priority + signals, triage surface only.

                 Phase 1.5 UI: compact signal cards, per-signal
                 confidence + sample size badges, raw metric tooltips
                 via expand toggle, persistent "uncalibrated" + "review
                 aid only" disclaimer. --}}
            @if (! empty($c['counter_intel']) && empty($c['counter_intel']['not_found']))
                @php
                    $ci = $c['counter_intel'];
                    $p1 = $ci['phase1_signals'] ?? null;
                    $anom = $ci['anomaly'] ?? null;
                    $bandColors = [
                        'critical'  => ['#7f1d1d', '#fca5a5', 'rgba(239,68,68,0.12)', 'rgba(239,68,68,0.4)'],
                        'high'      => ['#9a3412', '#fdba74', 'rgba(249,115,22,0.10)', 'rgba(249,115,22,0.35)'],
                        'elevated'  => ['#854d0e', '#fde68a', 'rgba(234,179,8,0.10)', 'rgba(234,179,8,0.30)'],
                        'note_only' => ['#1e3a8a', '#bfdbfe', 'rgba(59,130,246,0.08)', 'rgba(59,130,246,0.25)'],
                        'clean'     => ['#14532d', '#86efac', 'rgba(34,197,94,0.08)', 'rgba(34,197,94,0.25)'],
                        'insufficient_history' => ['#1f2937', '#9ca3af', 'rgba(255,255,255,0.04)', 'rgba(255,255,255,0.10)'],
                    ];
                    $confColors = [
                        'high'   => ['#86efac', 'rgba(34,197,94,0.10)'],
                        'medium' => ['#fde68a', 'rgba(234,179,8,0.10)'],
                        'low'    => ['#fca5a5', 'rgba(239,68,68,0.10)'],
                        'insufficient' => ['#9ca3af', 'rgba(255,255,255,0.04)'],
                    ];
                    $p1Band = $p1['band'] ?? 'clean';
                    [$bgDark, $fg, $bgLight, $border] = $bandColors[$p1Band] ?? $bandColors['clean'];
                    $confidence = $p1['confidence'] ?? 'medium';
                    [$confFg, $confBg] = $confColors[$confidence] ?? $confColors['medium'];
                    $visibleSignals = array_values(array_filter($p1['signals'] ?? [], fn ($s) => ($s['severity'] ?? '') !== 'suppressed'));
                    $suppressedSignals = array_values(array_filter($p1['signals'] ?? [], fn ($s) => ($s['severity'] ?? '') === 'suppressed'));
                @endphp
                <div style="margin-top:1.5rem; padding-top:1rem; border-top:1px solid rgba(255,255,255,0.06);">
                    {{-- Header strip: title + band badge + confidence badge + uncalibrated badge --}}
                    <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.6rem; flex-wrap:wrap;">
                        <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin:0;">
                            Counter-Intel · review signals
                        </h3>
                        <span title="Phase 1 review-priority band — derived from the count of independent signals firing."
                              style="font-size:0.55rem; padding:2px 8px; border-radius:4px; text-transform:uppercase; letter-spacing:0.08em;
                                     background:{{ $bgLight }}; color:{{ $fg }}; border:1px solid {{ $border }}; cursor:help;">
                            {{ str_replace('_', ' ', $p1Band) }}
                        </span>
                        <span title="Aggregate confidence in this band, derived from sample size, cohort size, fresh corp joins, and ESI artefact density."
                              style="font-size:0.55rem; padding:2px 8px; border-radius:4px; text-transform:uppercase; letter-spacing:0.08em;
                                     background:{{ $confBg }}; color:{{ $confFg }}; border:1px solid {{ $confBg }}; cursor:help;">
                            confidence: {{ $confidence }}
                        </span>
                        <span title="The Phase 1 signal model is uncalibrated against ground-truth labels yet. Treat output as a triage hint, not a verdict."
                              style="font-size:0.55rem; padding:2px 8px; border-radius:4px; text-transform:uppercase; letter-spacing:0.08em;
                                     background:rgba(234,179,8,0.10); color:#fde68a; border:1px solid rgba(234,179,8,0.30); cursor:help;">
                            uncalibrated
                        </span>
                        @if ($p1 && ! empty($p1['demoted']))
                            <span title="Band was demoted one level due to low confidence (small sample, tiny cohort, missing relative data, or ESI artefacts). Raw band before demotion: {{ $p1['raw_band'] ?? '' }}"
                                  style="font-size:0.55rem; padding:2px 8px; border-radius:4px; text-transform:uppercase; letter-spacing:0.08em;
                                         background:rgba(255,255,255,0.04); color:#9ca3af; border:1px solid rgba(255,255,255,0.10); cursor:help;">
                                demoted
                            </span>
                        @endif
                        <span style="font-size:0.6rem; color:#7a7a82; margin-left:auto; font-style:italic;">
                            viewer bloc · cached 10 min · 90-day window
                        </span>
                    </div>

                    {{-- Persistent "review aid only" disclaimer --}}
                    @if (! empty($p1['caveat']))
                        <div style="background:rgba(234,179,8,0.06); border:1px solid rgba(234,179,8,0.25); border-radius:6px; padding:0.5rem 0.75rem; margin-bottom:0.6rem; color:#fde68a; font-size:0.72rem; display:flex; gap:0.4rem; align-items:flex-start;">
                            <span style="font-size:0.85rem; line-height:1;" aria-hidden="true">⚠</span>
                            <span style="line-height:1.3;">{{ $p1['caveat'] }} This surface is advisory intelligence — never use as the sole basis for a punitive action.</span>
                        </div>
                    @endif

                    {{-- Headline summary --}}
                    @if (! empty($p1['evidence_summary']))
                        <div style="background:{{ $bgLight }}; border:1px solid {{ $border }}; border-radius:6px; padding:0.7rem 0.9rem; margin-bottom:0.75rem; color:{{ $fg }}; font-size:0.82rem;">
                            {{ $p1['evidence_summary'] }}
                        </div>
                    @endif

                    {{-- Signal cards (compact, with confidence + sample size + raw expand) --}}
                    @if (! empty($visibleSignals))
                        <div style="display:grid; gap:0.45rem; grid-template-columns:repeat(auto-fit, minmax(360px, 1fr));">
                            @foreach ($visibleSignals as $sig)
                                @php
                                    $isFlag = ($sig['severity'] ?? 'note') === 'flag';
                                    $rowBg = $isFlag ? 'rgba(239,68,68,0.06)' : 'rgba(255,255,255,0.02)';
                                    $rowBorder = $isFlag ? 'rgba(239,68,68,0.30)' : 'rgba(255,255,255,0.06)';
                                    $accent = $isFlag ? '#fca5a5' : '#7dd3fc';
                                    $sigConf = $sig['confidence'] ?? 'medium';
                                    [$sigConfFg, $sigConfBg] = $confColors[$sigConf] ?? $confColors['medium'];
                                    $sampleN = $sig['sample_size'] ?? null;
                                    $rawId = 'rawsig_' . $c['character_id'] . '_' . ($sig['reason_code'] ?? $sig['key'] ?? 'unk');
                                @endphp
                                <div style="display:flex; gap:0.6rem; align-items:flex-start; background:{{ $rowBg }}; border:1px solid {{ $rowBorder }}; border-radius:6px; padding:0.55rem 0.75rem;">
                                    <span style="display:inline-block; width:0.55rem; height:0.55rem; border-radius:50%; background:{{ $accent }}; flex-shrink:0; margin-top:0.45rem;"></span>
                                    <div style="flex:1; min-width:0;">
                                        <div style="display:flex; gap:0.4rem; align-items:center; flex-wrap:wrap; margin-bottom:0.2rem;">
                                            <span style="font-size:0.55rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">
                                                {{ str_replace('_', ' ', $sig['reason_code'] ?? $sig['key']) }}
                                            </span>
                                            @if ($isFlag)
                                                <span style="font-size:0.55rem; color:{{ $accent }}; text-transform:uppercase; letter-spacing:0.08em;">flag</span>
                                            @else
                                                <span style="font-size:0.55rem; color:#9ca3af; text-transform:uppercase; letter-spacing:0.08em;">note</span>
                                            @endif
                                            <span title="Per-signal confidence — derived from this signal's sample size and inputs."
                                                  style="font-size:0.5rem; padding:1px 6px; border-radius:3px; text-transform:uppercase; letter-spacing:0.06em;
                                                         background:{{ $sigConfBg }}; color:{{ $sigConfFg }}; cursor:help;">
                                                {{ $sigConf }}
                                            </span>
                                            @if ($sampleN !== null)
                                                <span title="Underlying sample size used to compute this signal."
                                                      style="font-size:0.5rem; padding:1px 6px; border-radius:3px; background:rgba(255,255,255,0.04); color:#9ca3af; cursor:help;">
                                                    n={{ number_format((int) $sampleN) }}
                                                </span>
                                            @endif
                                            @if (! empty($sig['raw']))
                                                <button type="button"
                                                        onclick="document.getElementById('{{ $rawId }}').classList.toggle('hidden')"
                                                        style="font-size:0.5rem; padding:1px 6px; border-radius:3px; background:rgba(255,255,255,0.04); color:#9ca3af; border:none; cursor:pointer; text-transform:uppercase; letter-spacing:0.06em;">
                                                    raw
                                                </button>
                                            @endif
                                        </div>
                                        <div style="font-size:0.82rem; color:#e5e5e7; line-height:1.4;">
                                            {{ $sig['text'] }}
                                        </div>
                                        @if (! empty($sig['raw']))
                                            <div id="{{ $rawId }}" class="hidden" style="margin-top:0.35rem; padding:0.4rem 0.55rem; background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.05); border-radius:4px; font-family:ui-monospace,monospace; font-size:0.68rem; color:#cbd5e1; overflow-x:auto; white-space:pre-wrap;">{{ json_encode($sig['raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div style="font-size:0.78rem; color:#7a7a82; font-style:italic; padding:0.5rem 0.75rem; background:rgba(255,255,255,0.02); border:1px dashed rgba(255,255,255,0.08); border-radius:6px;">
                            No Phase 1 signals are flashing — pilot reads as normal vs the cohort.
                        </div>
                    @endif

                    @if (! empty($suppressedSignals))
                        <details style="margin-top:0.5rem;">
                            <summary style="cursor:pointer; font-size:0.65rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">
                                Suppressed diagnostics · {{ count($suppressedSignals) }}
                                <span style="text-transform:none; letter-spacing:0; font-style:italic; color:#6b7280;">
                                    — bloc-relative signals that fire baseline-true and are kept for audit only
                                </span>
                            </summary>
                            <div style="display:grid; gap:0.3rem; margin-top:0.4rem;">
                                @foreach ($suppressedSignals as $sig)
                                    <div style="font-size:0.72rem; color:#9ca3af; padding:0.35rem 0.6rem; background:rgba(255,255,255,0.02); border:1px dashed rgba(255,255,255,0.08); border-radius:4px;">
                                        <span style="font-size:0.55rem; text-transform:uppercase; letter-spacing:0.08em; color:#6b7280;">
                                            {{ str_replace('_', ' ', $sig['reason_code'] ?? $sig['key']) }} · suppressed ({{ $sig['suppression_reason'] ?? 'unknown' }})
                                        </span>
                                        <div style="margin-top:0.15rem;">{{ $sig['text'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif

                    {{-- Aggregate sample sizes (small footer line) --}}
                    @if (! empty($p1['sample_sizes']))
                        @php $ss = $p1['sample_sizes']; @endphp
                        <div style="margin-top:0.5rem; font-size:0.6rem; color:#6b7280; font-style:italic;">
                            sample · battles {{ $ss['battles'] ?? 0 }} · km(att) {{ $ss['killmails_attacker'] ?? 0 }} · km(vic) {{ $ss['killmails_victim'] ?? 0 }} · cohort {{ $ss['cohort_size'] ?? 0 }} · last activity {{ $ss['days_since_last_activity'] ?? '—' }}d ago
                        </div>
                    @endif

                    {{-- Phase 4 — log-derived operational analytics. --}}
                    @php $p4 = $ci['phase4_signals'] ?? null; @endphp
                    @if ($p4)
                        <div style="margin-top:1rem; padding-top:0.8rem; border-top:1px dashed rgba(255,255,255,0.06);">
                            <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.5rem;">
                                <h4 style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.10em; color:#7a7a82; margin:0;">
                                    Phase 4 · operational evidence
                                </h4>
                                <span title="Phase 4 signals come from uploaded EVE log streams. Always rendered as supporting evidence, never as a flag."
                                      style="font-size:0.5rem; padding:1px 6px; border-radius:3px; text-transform:uppercase; letter-spacing:0.06em;
                                             background:rgba(99,102,241,0.10); color:#c7d2fe; cursor:help;">
                                    log-derived · advisory
                                </span>
                            </div>

                            @if ($p4['intel_reliability'])
                                @php $ir = $p4['intel_reliability']; @endphp
                                <div style="font-size:0.78rem; color:#cbd5e1; padding:0.5rem 0.7rem; background:rgba(99,102,241,0.04); border:1px solid rgba(99,102,241,0.20); border-radius:5px; margin-bottom:0.4rem;">
                                    <span style="font-size:0.55rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">intel reliability · {{ $ir['confidence'] }} (n={{ $ir['sample_size'] }})</span><br>
                                    {{ $ir['text'] }}
                                </div>
                            @endif

                            @if ($p4['fleet_lurker'])
                                @php $fl = $p4['fleet_lurker']; @endphp
                                <div style="font-size:0.78rem; color:#fde68a; padding:0.5rem 0.7rem; background:rgba(234,179,8,0.06); border:1px solid rgba(234,179,8,0.25); border-radius:5px; margin-bottom:0.4rem;">
                                    <span style="font-size:0.55rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">fleet lurker · {{ $fl['confidence'] }} (n={{ $fl['sample_size'] }})</span><br>
                                    {{ $fl['text'] }}
                                </div>
                            @endif

                            @if (! empty($p4['recent_timeline']))
                                <details style="margin-top:0.4rem;">
                                    <summary style="cursor:pointer; font-size:0.65rem; color:#9ca3af; text-transform:uppercase; letter-spacing:0.08em;">
                                        Recent operational timeline · {{ count($p4['recent_timeline']) }} event(s)
                                    </summary>
                                    <ul style="margin-top:0.4rem; padding-left:1rem; font-size:0.72rem; color:#cbd5e1;">
                                        @foreach ($p4['recent_timeline'] as $te)
                                            <li style="margin-bottom:0.2rem;">
                                                <span style="color:#7a7a82; font-family:ui-monospace,monospace; font-size:0.65rem;">{{ $te['event_timestamp'] }}</span>
                                                <span style="color:#fdba74; font-size:0.6rem; text-transform:uppercase; letter-spacing:0.04em; margin:0 0.3rem;">{{ str_replace('_', ' ', $te['timeline_type']) }}</span>
                                                @if ($te['solar_system_name'])
                                                    <span style="color:#86efac; font-size:0.65rem;">{{ $te['solar_system_name'] }}</span>
                                                @endif
                                                <div style="color:#cbd5e1; margin-left:1rem;">{{ $te['event_summary'] }}</div>
                                            </li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif

                            @if (! empty($p4['session_correlations']))
                                <details style="margin-top:0.4rem;">
                                    <summary style="cursor:pointer; font-size:0.65rem; color:#9ca3af; text-transform:uppercase; letter-spacing:0.08em;">
                                        Session correlations · top {{ count($p4['session_correlations']) }}
                                        <span style="text-transform:none; letter-spacing:0; color:#6b7280; font-style:italic; font-weight:400;">— supporting only, never identity proof</span>
                                    </summary>
                                    <table style="width:100%; margin-top:0.4rem; font-size:0.7rem;">
                                        @foreach ($p4['session_correlations'] as $sc)
                                            <tr style="border-bottom:1px solid rgba(255,255,255,0.04);">
                                                <td style="padding:0.25rem 0.4rem; color:#cbd5e1;">{{ $sc['peer'] }}</td>
                                                <td style="padding:0.25rem 0.4rem; color:#9ca3af; text-align:right;">{{ number_format($sc['shared_minutes']) }}m shared</td>
                                                <td style="padding:0.25rem 0.4rem; color:#fdba74; text-align:right;">{{ number_format($sc['score'], 2) }}</td>
                                                <td style="padding:0.25rem 0.4rem; color:#7a7a82; font-size:0.55rem; text-transform:uppercase;">{{ $sc['confidence'] }}</td>
                                            </tr>
                                        @endforeach
                                    </table>
                                </details>
                            @endif
                        </div>
                    @endif

                    {{-- Bloc-scoped watchlist button. Embedded Livewire
                         component manages a single ci_watchlist_entries
                         row for this (character, bloc) pair. --}}
                    @if (! empty($c['viewer_bloc_id']))
                        <livewire:counter-intel-watchlist-button
                            :character-id="$c['character_id']"
                            :viewer-bloc-id="$c['viewer_bloc_id']"
                            :key="'wl-' . $c['character_id']" />
                    @endif

                    {{-- Existing per-bloc anomaly band, ring members, combat anomaly + cohort context.
                         These pre-date Phase 1 but live in the same review surface, so we keep them
                         here so the operator sees one unified Counter-Intel view rather than two. --}}
                    @if ($anom !== null)
                        @php
                            $existingBand = $anom['review_priority_band'] ?? null;
                            $score = $anom['review_priority_score'] ?? null;
                        @endphp
                        @if (! empty($ci['explanation']))
                            <details style="margin-top:0.75rem;">
                                <summary style="cursor:pointer; font-size:0.7rem; color:#9ca3af; text-transform:uppercase; letter-spacing:0.08em;">
                                    Cohort + graph evidence
                                    @if ($existingBand)
                                        <span style="text-transform:none; letter-spacing:0; font-size:0.7rem; color:#7a7a82; margin-left:0.4rem;">
                                            · existing band: {{ str_replace('_', ' ', $existingBand) }}@if ($score !== null) · score {{ number_format((float) $score, 2) }}@endif
                                        </span>
                                    @endif
                                </summary>
                                <ul style="margin-top:0.5rem; padding-left:1.1rem; color:#cbd5e1; font-size:0.78rem; line-height:1.55;">
                                    @foreach ($ci['explanation'] as $line)
                                        <li style="margin-bottom:0.25rem;">{{ $line }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                        @if (! empty($ci['combat_anomaly']['signals']))
                            <details style="margin-top:0.4rem;">
                                <summary style="cursor:pointer; font-size:0.7rem; color:#9ca3af; text-transform:uppercase; letter-spacing:0.08em;">
                                    Combat behaviour signals
                                    <span style="text-transform:none; letter-spacing:0; font-size:0.7rem; color:#7a7a82; margin-left:0.4rem;">
                                        · {{ $ci['combat_anomaly']['headline'] ?? '' }}
                                    </span>
                                </summary>
                                <ul style="margin-top:0.5rem; padding-left:1.1rem; color:#cbd5e1; font-size:0.78rem; line-height:1.55;">
                                    @foreach ($ci['combat_anomaly']['signals'] as $cs)
                                        @php $cls = ($cs['direction'] ?? 'reinforces') === 'reinforces' ? '#fca5a5' : '#86efac'; @endphp
                                        <li style="margin-bottom:0.25rem;"><span style="color:{{ $cls }};">●</span> {{ $cs['text'] }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                        @if (! empty($ci['ring_members']))
                            <details style="margin-top:0.4rem;">
                                <summary style="cursor:pointer; font-size:0.7rem; color:#9ca3af; text-transform:uppercase; letter-spacing:0.08em;">
                                    Recurring ring · {{ count($ci['ring_members']) }} other pilots
                                </summary>
                                <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:0.4rem; margin-top:0.5rem;">
                                    @foreach (array_slice($ci['ring_members'], 0, 12) as $rm)
                                        <a href="?cid={{ $rm['character_id'] }}" style="text-decoration:none; display:flex; gap:0.4rem; align-items:center; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:5px; padding:0.35rem 0.55rem; color:#e5e5e7; font-size:0.78rem;">
                                            <img src="https://images.evetech.net/characters/{{ $rm['character_id'] }}/portrait?size=32" referrerpolicy="no-referrer" style="width:20px;height:20px;border-radius:50%;" alt="">
                                            <span style="flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $rm['character_name'] ?? '#'.$rm['character_id'] }}</span>
                                            @if (! empty($rm['review_priority_band']))
                                                <span style="font-size:0.6rem; color:#7a7a82;">{{ $rm['review_priority_band'] }}</span>
                                            @endif
                                        </a>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    @endif
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

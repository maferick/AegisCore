<x-filament-panels::page>
    @php
        $fmtIsk = function (float $v): string {
            if ($v >= 1e12) return number_format($v / 1e12, 2) . ' T';
            if ($v >= 1e9)  return number_format($v / 1e9, 2) . ' B';
            if ($v >= 1e6)  return number_format($v / 1e6, 2) . ' M';
            return number_format($v, 0);
        };
        $roleColor = [
            'fc' => '#fde047',
            'command' => '#f0abfc',
            'logi' => '#6ee7b7',
            'tackle' => '#67e8f9',
            'bomber' => '#fdba74',
            'mainline_dps' => '#93c5fd',
        ];
        $roleLabel = [
            'fc' => 'Fleet Commanders',
            'command' => 'Command / Boosters',
            'logi' => 'Logi',
            'tackle' => 'Tackle',
            'bomber' => 'Bomber',
            'mainline_dps' => 'Mainline DPS',
        ];
    @endphp

    {{-- Search --}}
    <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
        <form method="get" style="display:flex; gap:0.5rem; align-items:center;">
            <input type="text" name="q" value="{{ $search }}" placeholder="Search alliance name (3+ chars)…"
                   style="flex:1; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.1); color:#e5e5e7; padding:0.5rem 0.75rem; border-radius:4px; font-size:0.85rem;">
            <button type="submit" style="background:#4338ca; color:#fff; border:none; padding:0.5rem 1rem; border-radius:4px; font-size:0.8rem; cursor:pointer;">Search</button>
            @if ($alliance || $search)
                <a href="?" style="font-size:0.75rem; color:#7a7a82;">clear</a>
            @endif
        </form>
        @if (! empty($suggestions))
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:0.35rem; margin-top:0.75rem;">
                @foreach ($suggestions as $s)
                    <a href="?aid={{ $s['alliance_id'] }}"
                       style="text-decoration:none; display:flex; gap:0.5rem; align-items:center;
                              background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08);
                              border-radius:5px; padding:0.4rem 0.6rem; color:#e5e5e7; font-size:0.8rem;">
                        <img src="https://images.evetech.net/alliances/{{ $s['alliance_id'] }}/logo?size=32"
                             referrerpolicy="no-referrer" style="width:22px;height:22px;border-radius:3px;" alt="">
                        <span>{{ $s['name'] }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    @if (! $alliance)
        @if (! $search)
            <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4"
                 style="font-size:0.78rem; color:#cbd5e1; line-height:1.55;">
                <div style="font-size:0.75rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:0.4rem;">
                    How to look up an alliance
                </div>
                <ul style="margin:0 0 0.5rem 1.2rem; padding:0;">
                    <li>Type 3+ characters of an alliance name (e.g. <em>Goon</em>, <em>Frat</em>, <em>TEST</em>).</li>
                    <li>Search by ticker tag too — most alliances are searchable by either.</li>
                    <li>Drill in from a killmail, character lookup, or watchlist entry instead of typing.</li>
                </ul>
                <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:0.6rem;">
                    <a href="/portal/intelligence/director-strategic"
                       style="text-decoration:none; padding:6px 12px; background:rgba(165,180,252,0.10); color:#a5b4fc; border:1px solid rgba(165,180,252,0.25); border-radius:5px; font-size:0.75rem;">
                        Director strategic view →
                    </a>
                    <a href="/portal/intelligence/operations-heatmap"
                       style="text-decoration:none; padding:6px 12px; background:rgba(253,186,116,0.10); color:#fdba74; border:1px solid rgba(253,186,116,0.25); border-radius:5px; font-size:0.75rem;">
                        Operations heatmap →
                    </a>
                </div>
            </div>
        @else
            <div style="font-size:0.78rem; color:#7a7a82; font-style:italic; padding:0.5rem;">
                No alliance match — refine the search above.
            </div>
        @endif
    @else
        @php $a = $alliance; $h = $headline ?? []; @endphp

        {{-- Header --}}
        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
            <div style="display:flex; gap:1rem; align-items:center;">
                <img src="https://images.evetech.net/alliances/{{ $a['id'] }}/logo?size=64"
                     referrerpolicy="no-referrer" style="width:56px;height:56px;border-radius:8px;" alt="">
                <div style="flex:1;">
                    <div style="font-size:1.1rem; font-weight:700; color:#e5e5e7;">
                        {{ $a['name'] }}
                        @if (! empty($a['ticker']))
                            <span style="font-size:0.7rem; color:#7a7a82; font-weight:500;">&lt;{{ $a['ticker'] }}&gt;</span>
                        @endif
                    </div>
                    <div style="font-size:0.72rem; color:#9ca3af; margin-top:0.15rem;">
                        @if ($a['bloc'])
                            <span style="color:#86efac;">{{ $a['bloc'] }}{{ $a['role'] ? ' · '.$a['role'] : '' }}</span>
                        @else
                            <span style="color:#fcd34d;">no ground-truth bloc</span>
                        @endif
                        · <span style="color:#cbd5e1;">{{ number_format($a['pilot_count']) }} active pilots (90d)</span>
                    </div>
                    @if (! empty($a['creator_name']) || ! empty($a['executor_name']))
                        <div style="font-size:0.68rem; color:#a5b4fc; margin-top:0.25rem;">
                            @if ($a['creator_name'])
                                ⭐ Founder:
                                <a href="/portal/characters/lookup?cid={{ $a['creator_character_id'] }}" style="color:#fde047; text-decoration:none;">{{ $a['creator_name'] }}</a>
                            @endif
                            @if ($a['executor_name'])
                                · Executor corp: <span style="color:#e5e5e7;">{{ $a['executor_name'] }}</span>
                            @endif
                            @if ($a['date_founded'])
                                · Founded {{ \Carbon\Carbon::parse($a['date_founded'])->format('Y-m-d') }}
                            @endif
                        </div>
                    @endif
                </div>
            </div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:0.6rem; margin-top:0.8rem;">
                <div style="background:rgba(34,197,94,0.08); border:1px solid rgba(34,197,94,0.2); border-radius:6px; padding:0.55rem 0.8rem;">
                    <div style="font-size:0.6rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Kills (90d)</div>
                    <div style="font-size:1.05rem; font-weight:700; color:#4ade80;">{{ number_format($h['kills'] ?? 0) }}</div>
                </div>
                <div style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.2); border-radius:6px; padding:0.55rem 0.8rem;">
                    <div style="font-size:0.6rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Losses (90d)</div>
                    <div style="font-size:1.05rem; font-weight:700; color:#ff6b6b;">{{ number_format($h['losses'] ?? 0) }}</div>
                </div>
                <div style="background:rgba(34,197,94,0.08); border:1px solid rgba(34,197,94,0.2); border-radius:6px; padding:0.55rem 0.8rem;">
                    <div style="font-size:0.6rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">ISK destroyed</div>
                    <div style="font-size:1.05rem; font-weight:700; color:#4ade80;">{{ $fmtIsk((float) ($h['isk_destroyed'] ?? 0)) }}</div>
                </div>
                <div style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.2); border-radius:6px; padding:0.55rem 0.8rem;">
                    <div style="font-size:0.6rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">ISK lost</div>
                    <div style="font-size:1.05rem; font-weight:700; color:#ff6b6b;">{{ $fmtIsk((float) ($h['isk_lost'] ?? 0)) }}</div>
                </div>
            </div>
        </div>

        {{-- Role tiers --}}
        @foreach (['fc' => 'Fleet Commanders', 'command' => 'Command / Boosters', 'logi' => 'Logi', 'tackle' => 'Tackle', 'bomber' => 'Bomber', 'mainline_dps' => 'Mainline DPS'] as $roleKey => $label)
            @php $pilots = $layers[$roleKey] ?? []; @endphp
            @if (! empty($pilots))
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
                    <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.1em; color:{{ $roleColor[$roleKey] ?? '#9ca3af' }}; margin-bottom:0.5rem;">{{ $label }}</h3>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:0.5rem;">
                        @foreach ($pilots as $p)
                            @php
                                $isFounder = ! empty($a['creator_character_id']) && (int) $a['creator_character_id'] === (int) $p['character_id'];
                                $band = $p['anomaly_band'] ?? null;
                                $badgeClass = null;
                                $badgeColor = null;
                                $badgeBg = null;
                                if ($band === 'critical') {
                                    $badgeClass = 'CRIT'; $badgeColor = '#fca5a5'; $badgeBg = 'rgba(239,68,68,0.25)';
                                } elseif ($band === 'high') {
                                    $badgeClass = 'HIGH'; $badgeColor = '#fdba74'; $badgeBg = 'rgba(251,146,60,0.2)';
                                } elseif ($band === 'elevated') {
                                    $badgeClass = 'ELEV'; $badgeColor = '#fcd34d'; $badgeBg = 'rgba(251,191,36,0.15)';
                                }
                                // border picks the strongest signal: critical > founder > none.
                                $borderColor = $badgeClass === 'CRIT' ? 'rgba(239,68,68,0.5)'
                                    : ($badgeClass === 'HIGH' ? 'rgba(251,146,60,0.4)'
                                    : ($isFounder ? 'rgba(250,204,21,0.35)' : 'rgba(255,255,255,0.08)'));
                            @endphp
                            <a href="/admin/counter-intel/{{ $p['character_id'] }}"
                               style="display:flex; gap:0.5rem; align-items:center; text-decoration:none;
                                      background:rgba(255,255,255,0.02); border:1px solid {{ $borderColor }};
                                      border-radius:5px; padding:0.4rem 0.6rem; color:#e5e5e7;">
                                <img src="https://images.evetech.net/characters/{{ $p['character_id'] }}/portrait?size=32"
                                     referrerpolicy="no-referrer" style="width:28px;height:28px;border-radius:50%;" alt="">
                                <div style="flex:1; min-width:0;">
                                    <div style="font-size:0.82rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:flex; align-items:center; gap:0.3rem;">
                                        <span style="flex:1; overflow:hidden; text-overflow:ellipsis;">{{ $p['name'] }}</span>
                                        @if ($badgeClass)
                                            <span title="Counter-intel band {{ $band }} · score {{ $p['anomaly_score'] !== null ? number_format($p['anomaly_score'], 2) : '—' }}"
                                                  style="display:inline-block; padding:0 5px; border-radius:8px; font-size:0.55rem; font-weight:700; letter-spacing:0.06em; background:{{ $badgeBg }}; color:{{ $badgeColor }};">
                                                {{ $badgeClass }}
                                            </span>
                                        @endif
                                        @if ($isFounder)
                                            <span title="Alliance founder — anomaly signals on this pilot are structurally expected" style="color:#fde047; font-size:0.62rem;">⭐</span>
                                        @endif
                                    </div>
                                    <div style="font-size:0.6rem; color:#7a7a82;">
                                        {{ round($p['role_pct'] * 100) }}% role · {{ number_format($p['killmails_attacker']) }} kms
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach

        {{-- Corps --}}
        @if (! empty($corps))
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
                <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.1em; color:#94a3b8; margin-bottom:0.5rem;">Top corporations (by active pilots)</h3>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:0.4rem;">
                    @foreach ($corps as $c)
                        <div style="display:flex; gap:0.5rem; align-items:center; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08); border-radius:5px; padding:0.4rem 0.6rem;">
                            <img src="https://images.evetech.net/corporations/{{ $c->corporation_id }}/logo?size=32"
                                 referrerpolicy="no-referrer" style="width:24px;height:24px;border-radius:3px;" alt="">
                            <div style="flex:1; min-width:0;">
                                <div style="font-size:0.82rem; color:#e5e5e7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $c->name ?? "Corp #{$c->corporation_id}" }}</div>
                                <div style="font-size:0.6rem; color:#7a7a82;">{{ number_format((int) $c->pilots) }} pilots · {{ number_format((int) $c->kms) }} kms</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Hour histogram --}}
        @php $hh = $hour_histogram ?? []; $hhMax = ! empty($hh) ? max($hh) : 0; @endphp
        @if ($hhMax > 0)
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
                <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.1em; color:#94a3b8; margin-bottom:0.4rem;">Active hours (UTC · 90d kill windows)</h3>
                <div style="display:grid; grid-template-columns: repeat(24, 1fr); gap:2px; align-items:end; height:56px;">
                    @for ($hr = 0; $hr < 24; $hr++)
                        @php $v = $hh[$hr] ?? 0; $pct = $hhMax > 0 ? round($v / $hhMax * 100) : 0; @endphp
                        <div title="{{ sprintf('%02d:00 UTC — %d killmails', $hr, $v) }}"
                             style="background:rgba(79,208,208,0.5); height:{{ max(2, $pct) }}%; border-radius:2px 2px 0 0;"></div>
                    @endfor
                </div>
                <div style="display:grid; grid-template-columns: repeat(24, 1fr); gap:2px; margin-top:3px;">
                    @for ($hr = 0; $hr < 24; $hr++)
                        <div style="font-size:0.55rem; color:#7a7a82; text-align:center;">{{ $hr % 3 === 0 ? sprintf('%02d', $hr) : '' }}</div>
                    @endfor
                </div>
            </div>
        @endif

        {{-- FC clusters (Neo4j-style) --}}
        @if (! empty($fc_clusters))
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
                <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.1em; color:#fde047; margin-bottom:0.6rem;">
                    FC clusters
                    <span style="font-size:0.6rem; color:#7a7a82; text-transform:none; letter-spacing:0.03em; font-weight:400; font-style:italic;">
                        — each FC + their top co-flyers (CI_CO_OCCURS_WITH, 90d)
                    </span>
                </h3>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:0.75rem;">
                    @foreach ($fc_clusters as $cluster)
                        @php $fc = $cluster['fc']; $crew = $cluster['crew']; @endphp
                        <div style="background:rgba(250,204,21,0.04); border:1px solid rgba(250,204,21,0.25); border-radius:6px; padding:0.6rem;">
                            <a href="/portal/characters/lookup?cid={{ $fc['character_id'] }}" style="display:flex; gap:0.5rem; align-items:center; text-decoration:none; color:#e5e5e7;">
                                <img src="https://images.evetech.net/characters/{{ $fc['character_id'] }}/portrait?size=64"
                                     referrerpolicy="no-referrer" style="width:40px;height:40px;border-radius:50%;border:2px solid rgba(250,204,21,0.4);" alt="">
                                <div>
                                    <div style="font-size:0.9rem; font-weight:600;">{{ $fc['name'] }}</div>
                                    <div style="font-size:0.6rem; color:#fde047;">FC · {{ round($fc['role_pct'] * 100) }}% role · {{ number_format($fc['killmails_attacker']) }} kms</div>
                                </div>
                            </a>
                            @if (! empty($crew))
                                <div style="display:flex; flex-wrap:wrap; gap:0.3rem; margin-top:0.6rem;">
                                    @foreach ($crew as $cp)
                                        <a href="/portal/characters/lookup?cid={{ $cp['character_id'] }}" title="{{ $cp['name'] }} · {{ $cp['event_count'] ?? $cp['distinct_interactions'] ?? 0 }}x co-flight"
                                           style="text-decoration:none; display:inline-flex; gap:0.25rem; align-items:center; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:4px; padding:0.2rem 0.4rem; color:#cbd5e1; font-size:0.7rem;">
                                            <img src="https://images.evetech.net/characters/{{ $cp['character_id'] }}/portrait?size=32"
                                                 referrerpolicy="no-referrer" style="width:16px;height:16px;border-radius:50%;" alt="">
                                            {{ $cp['name'] ?? '?' }}
                                            <span style="color:#7a7a82;">·</span>
                                            <span style="color:#86efac;">{{ $cp['event_count'] ?? $cp['distinct_interactions'] ?? 0 }}×</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</x-filament-panels::page>

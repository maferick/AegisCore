<x-filament-panels::page>
    @php
        $bandStyle = [
            'critical' => ['bg' => 'rgba(239,68,68,0.18)', 'fg' => '#fca5a5', 'border' => 'rgba(239,68,68,0.4)'],
            'high'     => ['bg' => 'rgba(251,146,60,0.15)', 'fg' => '#fdba74', 'border' => 'rgba(251,146,60,0.4)'],
            'elevated' => ['bg' => 'rgba(251,191,36,0.15)', 'fg' => '#fcd34d', 'border' => 'rgba(251,191,36,0.35)'],
            'below_threshold'      => ['bg' => 'rgba(148,163,184,0.08)', 'fg' => '#cbd5e1', 'border' => 'rgba(148,163,184,0.25)'],
            'cohort_unavailable'   => ['bg' => 'rgba(100,116,139,0.1)', 'fg' => '#94a3b8', 'border' => 'rgba(100,116,139,0.25)'],
            'insufficient_history' => ['bg' => 'rgba(100,116,139,0.1)', 'fg' => '#94a3b8', 'border' => 'rgba(100,116,139,0.25)'],
        ];
        $hostilityStyle = [
            'hostile' => '#fca5a5',
            'friendly' => '#6ee7b7',
            'unknown' => '#94a3b8',
        ];
        $fmtPct = fn ($v) => $v === null ? '—' : number_format((float) $v * 100, 0).'%';
    @endphp

    @if ($no_bloc)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">Link an EVE character under Account settings, or pass ?bloc_id=N.</p>
        </div>
    @elseif ($dossier['not_found'] ?? false)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">No counter-intel data for character #{{ $dossier['character_id'] }}.</p>
            <p class="text-xs" style="color:#7a7a82; margin-top:0.5rem;">This usually means the pilot has never been seen in a killmail within our window.</p>
        </div>
    @else
        @php
            $anomaly = $dossier['anomaly'] ?? null;
            $band = $anomaly['review_priority_band'] ?? 'cohort_unavailable';
            $style = $bandStyle[$band] ?? $bandStyle['below_threshold'];
        @endphp

        {{-- Header --}}
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
            <div style="display:flex; gap:1rem; align-items:flex-start;">
                <img src="https://images.evetech.net/characters/{{ $dossier['character_id'] }}/portrait?size=128"
                     referrerpolicy="no-referrer"
                     style="width:96px; height:96px; border-radius:8px; border:2px solid {{ $style['border'] }};" alt="">
                <div style="flex:1; min-width:0;">
                    <div style="display:flex; gap:0.8rem; align-items:center; flex-wrap:wrap;">
                        <h2 class="text-xl font-semibold">{{ $dossier['character_name'] }}</h2>
                        <span style="display:inline-block; padding:2px 10px; border-radius:12px; font-size:0.68rem; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; background:{{ $style['bg'] }}; color:{{ $style['fg'] }}; border:1px solid {{ $style['border'] }};">
                            {{ str_replace('_', ' ', $band) }}
                        </span>
                        @if ($anomaly && $anomaly['review_priority_score'] !== null)
                            <span style="color:#9ca3af; font-size:0.85rem;">score {{ number_format((float) $anomaly['review_priority_score'], 2) }}</span>
                        @endif
                        @auth
                            @php $onWatch = ! empty($watchlist_entry); @endphp
                            <button type="button" wire:click="toggleWatch"
                                    style="margin-left:auto; padding:3px 10px; border-radius:4px; font-size:0.72rem; font-weight:600;
                                           background:{{ $onWatch ? 'rgba(239,68,68,0.2)' : 'rgba(79,208,208,0.12)' }};
                                           color:{{ $onWatch ? '#fca5a5' : '#4fd0d0' }};
                                           border:1px solid {{ $onWatch ? 'rgba(239,68,68,0.4)' : 'rgba(79,208,208,0.3)' }};
                                           cursor:pointer;">
                                {{ $onWatch ? '✓ Watching — click to remove' : '☆ Add to watchlist' }}
                            </button>
                        @endauth
                    </div>
                    @if (! empty($watchlist_entry) && ! empty($watchlist_entry->note))
                        <div style="margin-top:0.4rem; font-size:0.75rem; color:#9ca3af; font-style:italic;">
                            Note: {{ $watchlist_entry->note }}
                        </div>
                    @endif
                    @if (! empty($dossier['affiliation']['current']))
                        <div style="font-size:0.85rem; color:#9ca3af; margin-top:0.3rem;">
                            {{ $dossier['affiliation']['current']['corp_name'] }}
                            @if ($dossier['affiliation']['current']['alliance_name'])
                                / {{ $dossier['affiliation']['current']['alliance_name'] }}
                            @endif
                        </div>
                    @endif
                    <div style="font-size:0.75rem; color:#7a7a82; margin-top:0.25rem;">
                        Perspective: {{ $viewer_bloc_name }} ·
                        Cohort: {{ $anomaly['cohort_size'] ?? 0 }} peers ({{ $anomaly['cohort_confidence'] ?? '—' }} confidence) ·
                        Window: {{ $dossier['feature']['window_days'] ?? '—' }}d
                    </div>
                </div>
            </div>
        </div>

        {{-- Review explanation (human-readable, fixed-template) --}}
        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
            <h3 style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin-bottom:0.6rem;">Why this priority</h3>
            <ul style="list-style:disc inside; font-size:0.88rem; line-height:1.6; color:#cbd5e1;">
                @foreach ($dossier['explanation'] as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
            @if (! empty($dossier['why_not_higher']))
                <div style="margin-top:0.75rem; padding-top:0.6rem; border-top:1px solid rgba(255,255,255,0.06);">
                    <h4 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin-bottom:0.4rem;">Why not higher</h4>
                    <ul style="list-style:disc inside; font-size:0.82rem; line-height:1.5; color:#94a3b8;">
                        @foreach ($dossier['why_not_higher'] as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- Score strip --}}
        @if ($anomaly)
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap:0.7rem; margin-bottom:1rem;">
                @php
                    $cards = [
                        ['Activity decile', $anomaly['activity_decile'] !== null ? 'd'.$anomaly['activity_decile'] : '—', '#cbd5e1'],
                        ['Affiliation anomaly', $fmtPct($anomaly['affiliation_anomaly_pct']), '#fca5a5'],
                        ['Hostile overlap', $fmtPct($anomaly['hostile_overlap_pct']), '#fca5a5'],
                        ['Bridge anomaly', $fmtPct($anomaly['bridge_anomaly_pct']), '#a5b4fc'],
                        ['Affiliation churn', $fmtPct($anomaly['affiliation_churn_pct']), '#fcd34d'],
                    ];
                @endphp
                @foreach ($cards as [$label, $val, $col])
                    <div style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06); border-radius:6px; padding:0.65rem 0.8rem;">
                        <div style="font-size:0.62rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">{{ $label }}</div>
                        <div style="font-size:1.1rem; font-weight:600; color:{{ $col }}; margin-top:0.1rem;">{{ $val }}</div>
                    </div>
                @endforeach
                <div style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.2); border-radius:6px; padding:0.65rem 0.8rem;">
                    <div style="font-size:0.62rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Hostile co-fly</div>
                    <div style="font-size:1.1rem; font-weight:600; color:#fca5a5; margin-top:0.1rem;">{{ number_format($anomaly['hostile_cooccurrence_count']) }}</div>
                    <div style="font-size:0.65rem; color:#7a7a82;">distinct hostile counterparts</div>
                </div>
                @if ($anomaly['recent_hostile_join'])
                    <div style="background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3); border-radius:6px; padding:0.65rem 0.8rem;">
                        <div style="font-size:0.62rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Recent change</div>
                        <div style="font-size:0.95rem; font-weight:600; color:#fca5a5; margin-top:0.1rem;">Joined a hostile-tagged alliance within 30d</div>
                    </div>
                @endif
            </div>
        @endif

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.25rem;">
            {{-- Hostile-linked history --}}
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin-bottom:0.6rem;">Hostile-linked affiliations in history</h3>
                @if (empty($dossier['hostile_alliances_in_history']))
                    <p style="font-size:0.82rem; color:#7a7a82; font-style:italic;">None.</p>
                @else
                    <div style="display:flex; flex-direction:column; gap:0.5rem;">
                        @foreach ($dossier['hostile_alliances_in_history'] as $a)
                            <div style="display:flex; gap:0.5rem; align-items:center; font-size:0.82rem;">
                                <img src="https://images.evetech.net/alliances/{{ $a['alliance_id'] }}/logo?size=32"
                                     referrerpolicy="no-referrer"
                                     style="width:24px; height:24px; border-radius:3px; flex-shrink:0;" alt="">
                                <div style="flex:1; min-width:0;">
                                    <div style="color:#fca5a5; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $a['alliance_name'] }}</div>
                                    <div style="font-size:0.66rem; color:#7a7a82;">first seen {{ \Carbon\Carbon::parse($a['first_seen'])->format('Y-m-d') }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Alliance history timeline (full, with hostility) --}}
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin-bottom:0.6rem;">Affiliation timeline</h3>
                @if (empty($dossier['affiliation']['timeline']))
                    <p style="font-size:0.82rem; color:#7a7a82; font-style:italic;">—</p>
                @else
                    <div style="display:flex; flex-direction:column; gap:0.35rem; max-height:320px; overflow-y:auto;">
                        @foreach ($dossier['affiliation']['timeline'] as $row)
                            @php $hcolor = $hostilityStyle[$row['hostility'] ?? 'unknown'] ?? '#94a3b8'; @endphp
                            <div style="display:flex; gap:0.5rem; align-items:center; font-size:0.8rem; padding:0.3rem 0; border-bottom:1px solid rgba(255,255,255,0.04);">
                                <img src="https://images.evetech.net/corporations/{{ $row['corp_id'] }}/logo?size=32"
                                     referrerpolicy="no-referrer"
                                     style="width:22px; height:22px; border-radius:3px; flex-shrink:0;" alt="">
                                <div style="flex:1; min-width:0;">
                                    <div style="color:#e5e5e7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                        {{ $row['corp_name'] }}
                                        @if ($row['alliance_name'])
                                            <span style="color:{{ $hcolor }}; font-size:0.85em;">/ {{ $row['alliance_name'] }}</span>
                                        @endif
                                    </div>
                                    <div style="font-size:0.64rem; color:#7a7a82;">
                                        {{ \Carbon\Carbon::parse($row['start_date'])->format('Y-m-d') }}
                                        → {{ $row['end_date'] ? \Carbon\Carbon::parse($row['end_date'])->format('Y-m-d') : 'present' }}
                                    </div>
                                </div>
                                @if ($row['hostility'] === 'hostile')
                                    <span style="font-size:0.6em; color:#fca5a5; border:1px solid rgba(239,68,68,0.35); border-radius:4px; padding:1px 5px;">HOSTILE</span>
                                @elseif ($row['hostility'] === 'friendly')
                                    <span style="font-size:0.6em; color:#6ee7b7; border:1px solid rgba(16,185,129,0.35); border-radius:4px; padding:1px 5px;">BLUE</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Cohort baseline ruler — E5 --}}
        @if (! empty($dossier['cohort_baseline']) && $anomaly)
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="margin-top:1rem;">
                <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin-bottom:0.6rem;">Cohort baseline</h3>
                @php
                    $barFor = function (?float $v, array $base): string {
                        if ($v === null) return '—';
                        $p5 = $base['p5'] ?? 0;
                        $p95 = $base['p95'] ?? 1;
                        $p50 = $base['p50'] ?? 0.5;
                        $marker = max(0, min(100, round(($v - $p5) / max(0.0001, $p95 - $p5) * 100)));
                        $median = max(0, min(100, round(($p50 - $p5) / max(0.0001, $p95 - $p5) * 100)));
                        return '<div style="position:relative; height:14px; background:rgba(255,255,255,0.04); border-radius:3px; overflow:hidden;">'
                            . '<div style="position:absolute; top:0; bottom:0; left:'.$median.'%; width:1px; background:rgba(255,255,255,0.2);"></div>'
                            . '<div style="position:absolute; top:0; bottom:0; left:calc('.$marker.'% - 2px); width:4px; background:#fca5a5; border-radius:2px;"></div>'
                            . '</div>';
                    };
                @endphp
                <div style="display:grid; grid-template-columns: 160px 1fr 80px; gap:0.6rem 0.8rem; font-size:0.75rem; align-items:center;">
                    @foreach (['affiliation_anomaly_pct' => 'Hostile history', 'hostile_overlap_pct' => 'Hostile co-flight', 'bridge_anomaly_pct' => 'Cross-cluster bridge'] as $col => $label)
                        @php $base = $dossier['cohort_baseline'][$col] ?? ['p5' => 0, 'p50' => 0, 'p95' => 1]; @endphp
                        <div style="color:#9ca3af;">{{ $label }}</div>
                        <div>{!! $barFor($anomaly[$col] ?? null, $base) !!}</div>
                        <div style="text-align:right; color:#e5e5e7; font-family:'JetBrains Mono',monospace; font-size:0.72rem;">
                            {{ $fmtPct($anomaly[$col] ?? null) }} <span style="color:#7a7a82;">· p50 {{ $fmtPct($base['p50']) }}</span>
                        </div>
                    @endforeach
                </div>
                <div style="margin-top:0.5rem; font-size:0.64rem; color:#7a7a82; font-style:italic;">
                    Red mark = this pilot's value · white line = cohort median · bar span = p5 → p95.
                </div>
            </div>
        @endif

        {{-- Ring neighbours — E6 --}}
        @if (! empty($dossier['ring_members']))
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="margin-top:1rem;">
                <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin-bottom:0.6rem;">
                    Recurring co-flight ring
                    <span style="font-size:0.6rem; color:#7a7a82; font-weight:400; letter-spacing:0; text-transform:none;">({{ count($dossier['ring_members']) }} other members, ranked by score)</span>
                </h3>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:0.4rem;">
                    @foreach ($dossier['ring_members'] as $m)
                        @php $s = $bandStyle[$m['review_priority_band']] ?? $bandStyle['below_threshold']; @endphp
                        <a href="/admin/counter-intel/{{ $m['character_id'] }}"
                           style="display:flex; gap:0.5rem; align-items:center; text-decoration:none;
                                  background:rgba(255,255,255,0.02); border:1px solid {{ $s['border'] }};
                                  border-radius:5px; padding:0.35rem 0.5rem; color:#e5e5e7;">
                            <img src="https://images.evetech.net/characters/{{ $m['character_id'] }}/portrait?size=32"
                                 referrerpolicy="no-referrer" style="width:24px;height:24px;border-radius:50%;" alt="">
                            <div style="flex:1; min-width:0;">
                                <div style="font-size:0.78rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $m['character_name'] ?? "Pilot #{$m['character_id']}" }}</div>
                                <div style="font-size:0.6rem; color:{{ $s['fg'] }};">{{ str_replace('_', ' ', $m['review_priority_band'] ?? '—') }} · {{ $m['review_priority_score'] !== null ? number_format((float) $m['review_priority_score'], 2) : '—' }}</div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Structural neighbours from Neo4j embeddings — E8 --}}
        @if (! empty($dossier['similar_pilots']))
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="margin-top:1rem;">
                <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin-bottom:0.6rem;">
                    Structural nearest neighbours <span style="font-size:0.6rem; font-weight:400; color:#7a7a82; letter-spacing:0; text-transform:none;">(FastRP cosine via Neo4j SIMILAR_TO_V2)</span>
                </h3>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:0.4rem;">
                    @foreach ($dossier['similar_pilots'] as $n)
                        @php
                            $s = $bandStyle[$n['band']] ?? $bandStyle['below_threshold'];
                        @endphp
                        <a href="/admin/counter-intel/{{ $n['character_id'] }}"
                           style="display:flex; gap:0.5rem; align-items:center; text-decoration:none;
                                  background:rgba(255,255,255,0.02); border:1px solid {{ $s['border'] }};
                                  border-radius:5px; padding:0.35rem 0.5rem; color:#e5e5e7;">
                            <img src="https://images.evetech.net/characters/{{ $n['character_id'] }}/portrait?size=32"
                                 referrerpolicy="no-referrer" style="width:24px;height:24px;border-radius:50%;" alt="">
                            <div style="flex:1; min-width:0;">
                                <div style="font-size:0.78rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $n['name'] ?? "Pilot #{$n['character_id']}" }}</div>
                                <div style="font-size:0.6rem; color:{{ $s['fg'] }};">
                                    cosine {{ number_format($n['sim'], 3) }}
                                    @if ($n['band']) · {{ str_replace('_', ' ', $n['band']) }} @endif
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <div style="margin-top:1rem;">
            <a href="/admin/counter-intel" style="font-size:0.85rem; color:#4fd0d0; text-decoration:none;">← back to review queue</a>
        </div>
    @endif
</x-filament-panels::page>

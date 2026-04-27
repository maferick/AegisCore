<x-filament-panels::page>
    @if (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">No bloc resolved.</p>
        </div>
    @else
        @php
            $sevColors = ['urgent' => '#fb7185', 'elevated' => '#fdba74', 'watch' => '#fde68a', 'info' => '#9ca3af',
                          'coalition_level' => '#d8b4fe', 'escalation' => '#fca5a5', 'strategic' => '#fdba74', 'tactical' => '#fde68a'];
        @endphp

        {{-- Verdict banner --}}
        <x-verdict-banner :verdict="$verdict ?? null" />

        {{-- Window switch --}}
        <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
            <div style="display:flex; gap:0.5rem; align-items:center; font-size:0.7rem;">
                <span style="color:#7a7a82;">window:</span>
                @foreach ([1, 3, 6, 12, 24] as $h)
                    @php $a = $h === $hours; @endphp
                    <a href="?hours={{ $h }}" style="padding:3px 8px; border-radius:4px; text-decoration:none;
                       background:{{ $a ? 'rgba(125,211,252,0.15)' : 'rgba(255,255,255,0.04)' }};
                       color:{{ $a ? '#7dd3fc' : '#9ca3af' }};">{{ $h }}h</a>
                @endforeach
            </div>
        </div>

        <div style="display:grid; grid-template-columns:minmax(0,1.4fr) minmax(0,1fr); gap:1rem;">
            <div style="display:grid; gap:0.75rem;">
                {{-- Active incidents --}}
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                        Active threats · last {{ $hours }}h ({{ count($active_incidents) }})
                    </h3>
                    @if (count($active_incidents) === 0)
                        <p style="font-size:0.75rem; color:#7a7a82; font-style:italic;">No tactical+ incidents in window.</p>
                    @else
                        <div style="display:grid; gap:0.3rem;">
                            @foreach ($active_incidents as $i)
                                @php $col = $sevColors[$i->severity] ?? '#9ca3af'; @endphp
                                <a href="/portal/operations/incidents/{{ $i->id }}" style="padding:0.4rem 0.55rem; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-left:3px solid {{ $col }}; border-radius:5px; text-decoration:none; display:block;">
                                    <div style="display:flex; gap:0.4rem; align-items:center; flex-wrap:wrap;">
                                        <span style="font-size:0.55rem; color:{{ $col }}; text-transform:uppercase; letter-spacing:0.08em;">{{ $i->severity }}</span>
                                        <span style="font-size:0.6rem; color:#a5b4fc;">{{ str_replace('_', ' ', $i->incident_type) }}</span>
                                        <span style="font-size:0.85rem; color:#86efac;">{{ $i->primary_system_name ?? '?' }}</span>
                                        <x-intel-freshness surface="incident"
                                            :timestamp="$i->end_at ?? $i->start_at"
                                            :persisted="$i->freshness_state ?? null" />
                                        <span style="margin-left:auto; font-size:0.6rem; color:#7a7a82;">{{ $i->start_at }}</span>
                                    </div>
                                    @if ($i->timeline_summary)
                                        <div style="font-size:0.7rem; color:#cbd5e1; margin-top:0.2rem;">{{ \Illuminate\Support\Str::limit($i->timeline_summary, 200) }}</div>
                                    @endif
                                    @if ($i->dscan_total_ships)
                                        <div style="font-size:0.6rem; color:#fdba74; margin-top:0.15rem;">{{ $i->dscan_total_ships }} ships on dscan</div>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Recent strong clusters --}}
                @if (count($recent_clusters) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                            Recent strong clusters ({{ count($recent_clusters) }})
                        </h3>
                        <table style="width:100%; font-size:0.7rem; color:#cbd5e1; border-collapse:collapse;">
                            <thead style="color:#7a7a82;">
                                <tr><th style="text-align:left;">system</th><th style="text-align:right;">reps</th><th style="text-align:right;">events</th><th style="text-align:right;">ships</th><th style="text-align:left;">qual</th><th style="text-align:left;">when</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($recent_clusters as $c)
                                    <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                        <td style="padding:3px 4px; color:#86efac;">{{ $c->primary_system_name }}</td>
                                        <td style="text-align:right; padding:3px 4px;">{{ $c->reporter_count }}</td>
                                        <td style="text-align:right; padding:3px 4px;">{{ $c->report_count }}</td>
                                        <td style="text-align:right; padding:3px 4px; color:{{ ($c->dscan_total_ships ?? 0) > 100 ? '#fdba74' : '#cbd5e1' }};">{{ $c->dscan_total_ships ?? '—' }}</td>
                                        <td style="padding:3px 4px;">{{ $c->quality }}</td>
                                        <td style="padding:3px 4px; color:#9ca3af; font-family:ui-monospace,monospace; font-size:0.6rem;">{{ $c->start_at }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div style="display:grid; gap:0.75rem;">
                {{-- Open alerts --}}
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                        Open urgent / elevated alerts ({{ count($open_alerts) }})
                    </h3>
                    @if (count($open_alerts) === 0)
                        <p style="font-size:0.7rem; color:#7a7a82; font-style:italic;">None.</p>
                    @else
                        <div style="display:grid; gap:0.3rem;">
                            @foreach ($open_alerts as $a)
                                @php $col = $sevColors[$a->severity] ?? '#9ca3af'; @endphp
                                <div style="padding:0.35rem 0.5rem; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-left:3px solid {{ $col }}; border-radius:4px;">
                                    <div style="display:flex; gap:0.3rem; align-items:center; font-size:0.7rem;">
                                        <span style="font-size:0.55rem; color:{{ $col }}; text-transform:uppercase;">{{ $a->severity }}</span>
                                        <x-intel-freshness surface="alert"
                                            :timestamp="$a->detected_at"
                                            :persisted="$a->freshness_state ?? null" />
                                        <span style="color:#e5e5e7; flex:1;">{{ $a->title }}</span>
                                    </div>
                                    @if ($a->summary)
                                        <div style="font-size:0.65rem; color:#cbd5e1; margin-top:0.2rem;">{{ $a->summary }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <div style="text-align:right; margin-top:0.4rem;"><a href="/portal/intelligence/alerts" style="font-size:0.6rem; color:#7dd3fc; text-decoration:none;">all alerts →</a></div>
                </div>

                {{-- Hot corridors --}}
                @if (count($hot_corridors) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                            Hot corridors ({{ count($hot_corridors) }})
                        </h3>
                        <div style="display:grid; gap:0.2rem;">
                            @foreach ($hot_corridors as $c)
                                <div style="font-size:0.7rem; padding:0.25rem 0.4rem; background:rgba(255,255,255,0.02); border-radius:4px;">
                                    <div style="color:#86efac; display:flex; gap:0.3rem; align-items:center;">
                                        <span style="flex:1;">{{ $c->from_system_name }} → {{ $c->to_system_name }}</span>
                                        <x-intel-freshness surface="corridor"
                                            :timestamp="$c->last_seen_at"
                                            :persisted="$c->freshness_state ?? null" />
                                    </div>
                                    <div style="color:#7a7a82; font-size:0.6rem;">
                                        {{ $c->transition_count }} transits · {{ $c->distinct_characters }} chars · <span style="color:#a5b4fc;">{{ $c->route_classification }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Hottest systems --}}
                @if (count($hottest_systems) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                            Hottest systems ({{ count($hottest_systems) }})
                        </h3>
                        <div style="display:grid; gap:0.15rem;">
                            @foreach ($hottest_systems as $s)
                                @php $col = $s->tier === 'strategic' ? '#fb7185' : '#fdba74'; @endphp
                                <a href="/portal/operations/heatmap?system={{ $s->solar_system_name }}" style="font-size:0.7rem; padding:0.2rem 0.4rem; background:rgba(255,255,255,0.02); border-radius:4px; text-decoration:none; color:#cbd5e1; display:flex; gap:0.3rem;">
                                    <span style="color:#86efac; flex:1;">{{ $s->solar_system_name }}</span>
                                    <span style="color:{{ $col }};">{{ number_format((float) $s->threat_score, 1) }}</span>
                                    <span style="color:#7a7a82; font-size:0.55rem;">{{ $s->tier }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Tempo --}}
                @if (count($tempo) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                            Fastest-response systems
                        </h3>
                        <div style="display:grid; gap:0.15rem;">
                            @foreach ($tempo as $t)
                                <div style="font-size:0.7rem; padding:0.2rem 0.4rem; background:rgba(255,255,255,0.02); border-radius:4px; display:flex; gap:0.3rem;">
                                    <span style="color:#86efac; flex:1;">{{ $t->solar_system_name }}</span>
                                    <span>{{ floor($t->intel_to_combat_median_seconds / 60) }}m {{ $t->intel_to_combat_median_seconds % 60 }}s</span>
                                    <span style="color:#7a7a82; font-size:0.55rem;">{{ $t->intel_to_combat_count }}×</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</x-filament-panels::page>

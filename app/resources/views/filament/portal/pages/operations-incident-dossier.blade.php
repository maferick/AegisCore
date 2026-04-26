<x-filament-panels::page>
    @if (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">No bloc resolved.</p>
        </div>
    @elseif (! empty($not_found))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">Incident #{{ $incident_id }} not found in this bloc's queue.</p>
            <p style="font-size:0.75rem; color:#7a7a82; margin-top:0.5rem;">
                <a href="/portal/operations/timeline" style="color:#c7d2fe;">← back to timeline</a>
            </p>
        </div>
    @else
        @php
            $sevColors = [
                'noise' => '#6b7280',
                'tactical' => '#fde68a',
                'strategic' => '#fdba74',
                'escalation' => '#fca5a5',
                'coalition_level' => '#d8b4fe',
            ];
            $kindColors = [
                'hostile_cluster' => '#fdba74',
                'fleet_formup' => '#86efac',
                'hostile_report' => '#fca5a5',
                'escalation' => '#fda4af',
                'combat_spike' => '#fdba74',
                'self_destruct_wave' => '#c084fc',
                'extraction' => '#a5b4fc',
                'disengagement' => '#fde68a',
                'crash_symptom' => '#9ca3af',
                'intel_gap' => '#fb923c',
                'unknown' => '#6b7280',
            ];
            $sevColor = $sevColors[$incident->severity] ?? '#9ca3af';
        @endphp

        {{-- Header --}}
        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3" style="border-left:4px solid {{ $sevColor }};">
            <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                <a href="/portal/operations/timeline" style="font-size:0.7rem; color:#9ca3af; text-decoration:none;">← timeline</a>
                <h2 style="margin:0; font-size:1.05rem; color:#e5e5e7;">
                    Incident #{{ $incident->id }}
                    @if ($incident->primary_system_name)
                        · <span style="color:#86efac;">{{ $incident->primary_system_name }}</span>
                    @endif
                </h2>
                <span style="font-size:0.6rem; color:{{ $sevColor }}; text-transform:uppercase; letter-spacing:0.08em; padding:2px 8px; border-radius:4px; background:rgba(255,255,255,0.04);">
                    {{ str_replace('_', ' ', $incident->severity) }}
                </span>
                <span style="font-size:0.6rem; color:#a5b4fc; text-transform:uppercase; letter-spacing:0.08em;">
                    {{ str_replace('_', ' ', $incident->incident_type) }}
                </span>
                <span style="font-size:0.6rem; color:#9ca3af;">confidence: {{ $incident->confidence }}</span>
                @if ($incident->battle_id)
                    <a href="/portal/killmails?battle={{ $incident->battle_id }}" style="font-size:0.6rem; color:#fdba74; text-decoration:none; padding:2px 8px; border-radius:4px; background:rgba(249,115,22,0.10);">
                        battle #{{ $incident->battle_id }} →
                    </a>
                @endif
            </div>
            <div style="margin-top:0.5rem; font-size:0.78rem; color:#cbd5e1;">{{ $incident->timeline_summary }}</div>
            @if (! empty($narrative_md))
                <div style="margin-top:0.6rem; padding:0.5rem 0.75rem; background:rgba(167,139,250,0.06); border-left:3px solid #a78bfa; border-radius:4px;">
                    <div style="font-size:0.55rem; color:#a78bfa; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:0.25rem;">Narrative</div>
                    <div style="font-size:0.78rem; color:#e5e5e7; line-height:1.5;">{!! \Illuminate\Support\Str::of($narrative_md)->markdown() !!}</div>
                </div>
            @endif
            <div style="margin-top:0.4rem; display:flex; gap:0.4rem; flex-wrap:wrap; font-size:0.65rem; color:#7a7a82;">
                <span><strong style="color:#cbd5e1;">{{ $incident->start_at }}</strong> → {{ $incident->end_at }}</span>
                <span>·</span>
                @if ($incident->participant_estimate)
                    <span>~{{ $incident->participant_estimate }} named hostiles</span>
                    <span>·</span>
                @endif
                <span>signals: {{ implode(', ', $signal_types) }}</span>
                @if ($incident->has_dscan)
                    <span>·</span>
                    <span style="color:#fdba74;">
                        dscan ✓
                        @if ($incident->dscan_total_ships)
                            ({{ number_format((int) $incident->dscan_total_ships) }} ships)
                        @endif
                    </span>
                @endif
            </div>
        </div>

        {{-- Two-column: fused timeline | sidebar --}}
        <div style="display:grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap:1rem;">
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.5rem;">
                    Fused timeline · {{ count($fused_strip) }} events
                </h3>
                @if (count($fused_strip) === 0)
                    <p style="font-size:0.78rem; color:#9ca3af;">No constituent events.</p>
                @else
                    <div style="display:grid; gap:0.35rem;">
                        @foreach ($fused_strip as $s)
                            @php $kc = $kindColors[$s['kind']] ?? '#9ca3af'; @endphp
                            <div style="padding:0.4rem 0.6rem; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:5px; border-left:3px solid {{ $kc }};">
                                <div style="display:flex; gap:0.4rem; align-items:center;">
                                    <span style="font-family:ui-monospace,monospace; font-size:0.65rem; color:#9ca3af;">{{ $s['ts'] }}</span>
                                    <span style="font-size:0.55rem; color:{{ $kc }}; text-transform:uppercase; letter-spacing:0.08em;">{{ str_replace('_', ' ', $s['kind']) }}</span>
                                    @if ($s['system'])
                                        <span style="font-size:0.65rem; color:#86efac;">{{ $s['system'] }}</span>
                                    @endif
                                </div>
                                <div style="font-size:0.75rem; color:#cbd5e1; margin-top:0.15rem;">{{ $s['detail'] }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div style="display:grid; gap:0.75rem;">
                {{-- Top named hostiles --}}
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                        Named hostiles · top {{ count($top_hostiles) }}
                    </h3>
                    @if (count($top_hostiles) === 0)
                        <p style="font-size:0.72rem; color:#7a7a82; font-style:italic;">No resolved hostile names in clusters.</p>
                    @else
                        <div style="display:grid; gap:0.25rem; max-height:360px; overflow:auto;">
                            @foreach ($top_hostiles as $h)
                                <a href="/portal/characters/lookup?cid={{ $h['character_id'] }}" style="display:flex; gap:0.4rem; align-items:center; padding:0.3rem 0.5rem; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:4px; text-decoration:none; color:#e5e5e7; font-size:0.7rem;">
                                    <img src="https://images.evetech.net/characters/{{ $h['character_id'] }}/portrait?size=32" referrerpolicy="no-referrer" style="width:18px; height:18px; border-radius:50%;" alt="">
                                    <span style="flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $h['name'] }}</span>
                                    <span style="color:#7a7a82; font-size:0.55rem;">×{{ $h['mentions'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Severity reasoning --}}
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                        Severity reasoning
                    </h3>
                    <div style="font-size:0.7rem; color:#cbd5e1; line-height:1.5;">
                        Tier <strong style="color:{{ $sevColor }};">{{ str_replace('_', ' ', $incident->severity) }}</strong>
                        with {{ count($signal_types) }} signal type(s).
                        @if (! empty($evidence_json['max_hostile_cluster_quality']))
                            Top cluster quality: <strong>{{ $evidence_json['max_hostile_cluster_quality'] }}</strong>.
                        @endif
                        @if (! empty($evidence_json['max_timeline_quality']))
                            Top timeline quality: <strong>{{ $evidence_json['max_timeline_quality'] }}</strong>.
                        @endif
                    </div>
                    <div style="font-size:0.6rem; color:#7a7a82; margin-top:0.3rem; font-style:italic;">
                        Severity rules: 1 signal=noise, 2=tactical, 3+ with strong cluster=strategic, full hostile→combat→disengage chain=escalation, ≥10 reporters AND ≥10 hostiles=coalition_level.
                    </div>
                </div>

                {{-- Constituent objects --}}
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                        Constituents
                    </h3>
                    <div style="font-size:0.7rem; color:#cbd5e1;">
                        {{ count($clusters) }} hostile cluster(s) · {{ count($timeline_events) }} timeline event(s)
                    </div>
                </div>

                {{-- Force composition summary (Phase 4.5D) --}}
                @if (! empty($force_summary))
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                            Force composition · {{ $force_summary['snapshots'] }} snapshot(s)
                        </h3>
                        <div style="display:grid; grid-template-columns:repeat(2,1fr); gap:0.25rem 0.5rem; font-size:0.7rem; color:#cbd5e1;">
                            <div><span style="color:#7a7a82;">peak ships:</span> <strong>{{ number_format($force_summary['peak_ship_total']) }}</strong></div>
                            <div><span style="color:#7a7a82;">peak logi:</span> <strong>{{ $force_summary['peak_logistics'] }}</strong></div>
                            <div><span style="color:#7a7a82;">peak tackle:</span> <strong>{{ $force_summary['peak_tackle'] }}</strong></div>
                            <div><span style="color:#7a7a82;">caps / supers:</span> <strong style="color:{{ ($force_summary['peak_capital'] + $force_summary['peak_super']) > 0 ? '#fca5a5' : '#cbd5e1' }};">{{ $force_summary['peak_capital'] }} / {{ $force_summary['peak_super'] }}</strong></div>
                            <div><span style="color:#7a7a82;">projection:</span> <strong>{{ str_replace('_',' ',$force_summary['projection'] ?? '—') }}</strong></div>
                            <div><span style="color:#7a7a82;">mobility:</span> <strong>{{ $force_summary['mobility'] ?? '—' }}</strong></div>
                            <div><span style="color:#7a7a82;">brawl range:</span> <strong>{{ $force_summary['brawl_range'] ?? '—' }}</strong></div>
                        </div>
                        @if (! empty($force_summary['top_doctrines']))
                            <div style="margin-top:0.5rem; font-size:0.65rem; color:#7a7a82;">Doctrines:</div>
                            <div style="display:flex; gap:0.3rem; flex-wrap:wrap; margin-top:0.2rem;">
                                @foreach ($force_summary['top_doctrines'] as $name => $hits)
                                    <span style="font-size:0.6rem; padding:2px 6px; border-radius:4px; background:rgba(167,139,250,0.10); color:#c4b5fd;">{{ $name }} ×{{ $hits }}</span>
                                @endforeach
                            </div>
                        @endif

                        @if (count($force_compositions) > 0)
                            <details style="margin-top:0.6rem;">
                                <summary style="font-size:0.6rem; color:#7dd3fc; cursor:pointer;">snapshot breakdown ({{ count($force_compositions) }})</summary>
                                <div style="display:grid; gap:0.25rem; margin-top:0.4rem; max-height:240px; overflow:auto;">
                                    @foreach ($force_compositions as $f)
                                        <div style="padding:0.35rem 0.5rem; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:4px; font-size:0.65rem;">
                                            <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
                                                <span style="color:#9ca3af; font-family:ui-monospace,monospace;">{{ $f->snapshot_at }}</span>
                                                <span style="color:#fdba74; font-weight:600;">{{ $f->ship_total }} ships</span>
                                                @if ($f->primary_doctrine_name)
                                                    <span style="color:#c4b5fd;">{{ $f->primary_doctrine_name }} ({{ round(($f->doctrine_match_pct ?? 0) * 100) }}%)</span>
                                                @endif
                                            </div>
                                            <div style="color:#7a7a82; margin-top:0.15rem;">
                                                logi {{ $f->estimated_logistics_count }} · tackle {{ $f->estimated_tackle_count }} · dps {{ $f->estimated_dps_count }}
                                                @if ($f->estimated_capital_count + $f->estimated_super_count > 0)
                                                    · <span style="color:#fca5a5;">cap {{ $f->estimated_capital_count }} / super {{ $f->estimated_super_count }}</span>
                                                @endif
                                                · {{ $f->projection_strength }} · {{ $f->mobility }} · {{ $f->brawl_range }}
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>

                    @if (count($force_transitions) > 0)
                        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                            <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                                Force transitions · {{ count($force_transitions) }}
                            </h3>
                            <div style="display:grid; gap:0.25rem;">
                                @foreach ($force_transitions as $t)
                                    @php
                                        $tColor = match($t->transition_type) {
                                            'tackle_to_capital','subcap_to_capital','escalation' => '#fca5a5',
                                            'logistics_spike','bomber_reinforcement' => '#fdba74',
                                            'kite_to_brawl','brawl_to_kite' => '#fde68a',
                                            'de_escalation' => '#86efac',
                                            default => '#9ca3af',
                                        };
                                    @endphp
                                    <div style="padding:0.35rem 0.5rem; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-left:3px solid {{ $tColor }}; border-radius:4px; font-size:0.65rem;">
                                        <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
                                            <span style="color:{{ $tColor }}; text-transform:uppercase; letter-spacing:0.06em;">{{ str_replace('_',' ',$t->transition_type) }}</span>
                                            <span style="color:#9ca3af; font-family:ui-monospace,monospace;">{{ $t->from_at }} → {{ $t->to_at }}</span>
                                        </div>
                                        <div style="color:#cbd5e1; margin-top:0.15rem;">
                                            ships Δ{{ $t->ship_count_delta > 0 ? '+' : '' }}{{ $t->ship_count_delta }}
                                            · logi Δ{{ $t->logistics_delta > 0 ? '+' : '' }}{{ $t->logistics_delta }}
                                            · tackle Δ{{ $t->tackle_delta > 0 ? '+' : '' }}{{ $t->tackle_delta }}
                                            @if ($t->capital_delta != 0)
                                                · <span style="color:#fca5a5;">cap Δ{{ $t->capital_delta > 0 ? '+' : '' }}{{ $t->capital_delta }}</span>
                                            @endif
                                            · {{ floor($t->duration_seconds / 60) }}m
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif

                {{-- dscan snapshots referenced by this incident --}}
                @if (! empty($dscan_snapshots) && count($dscan_snapshots) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                            dscan snapshots · {{ count($dscan_snapshots) }}
                        </h3>
                        <div style="display:grid; gap:0.3rem; max-height:280px; overflow:auto;">
                            @foreach ($dscan_snapshots as $d)
                                <div style="padding:0.4rem 0.55rem; background:rgba(253,186,116,0.05); border:1px solid rgba(253,186,116,0.20); border-radius:5px; font-size:0.7rem;">
                                    <div style="display:flex; gap:0.4rem; align-items:center; flex-wrap:wrap;">
                                        @if ($d->ship_count)
                                            <span style="color:#fdba74; font-weight:600;">{{ number_format((int) $d->ship_count) }} ships</span>
                                        @else
                                            <span style="color:#9ca3af; font-style:italic;">{{ $d->fetch_status }}</span>
                                        @endif
                                        <a href="{{ $d->url }}" target="_blank" rel="noopener" style="font-size:0.55rem; color:#7dd3fc; margin-left:auto; text-decoration:none;">view →</a>
                                    </div>
                                    @if ($d->top_ship_summary)
                                        <div style="font-size:0.65rem; color:#cbd5e1; margin-top:0.15rem;">{{ $d->top_ship_summary }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</x-filament-panels::page>

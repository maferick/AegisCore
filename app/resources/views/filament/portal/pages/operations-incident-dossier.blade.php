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
            <div style="margin-top:0.4rem; display:flex; gap:0.4rem; flex-wrap:wrap; font-size:0.65rem; color:#7a7a82;">
                <span><strong style="color:#cbd5e1;">{{ $incident->start_at }}</strong> → {{ $incident->end_at }}</span>
                <span>·</span>
                @if ($incident->participant_estimate)
                    <span>~{{ $incident->participant_estimate }} named hostiles</span>
                    <span>·</span>
                @endif
                <span>signals: {{ implode(', ', $signal_types) }}</span>
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
            </div>
        </div>
    @endif
</x-filament-panels::page>

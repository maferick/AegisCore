<x-filament-panels::page>
    <style>
        .aegis-md h1, .aegis-md h2 { font-size: 1.05rem; color: #e5e5e7; margin: 1rem 0 0.4rem; font-weight: 600; }
        .aegis-md h3 { font-size: 0.95rem; color: #cbd5e1; margin: 0.8rem 0 0.3rem; font-weight: 600; }
        .aegis-md h4 { font-size: 0.85rem; color: #cbd5e1; margin: 0.6rem 0 0.25rem; font-weight: 600; }
        .aegis-md p { margin: 0.4rem 0; }
        .aegis-md ul, .aegis-md ol { margin: 0.4rem 0 0.4rem 1.4rem; }
        .aegis-md li { margin: 0.15rem 0; }
        .aegis-md code { background: rgba(255,255,255,0.06); padding: 1px 5px; border-radius: 3px; font-size: 0.78rem; }
        .aegis-md a { color: #7dd3fc; text-decoration: underline; }
        .aegis-md strong { color: #f1f5f9; }
        .aegis-md hr { border: 0; border-top: 1px solid rgba(255,255,255,0.10); margin: 0.8rem 0; }
        .aegis-md blockquote { border-left: 3px solid rgba(255,255,255,0.15); padding-left: 0.7rem; color: #94a3b8; margin: 0.5rem 0; }
    </style>
    @if (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">No bloc resolved.</p>
        </div>
    @else
        {{-- Header + window switcher --}}
        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
            <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                <h2 style="margin:0; font-size:1.05rem; color:#e5e5e7;">
                    Intel digest · {{ $bloc_name }} · <span style="color:#86efac;">{{ $date }}</span>
                </h2>
                @if (! empty($generated_at))
                    <x-intel-freshness surface="digest"
                        :timestamp="$generated_at"
                        :persisted="$freshness_state ?? null"
                        :windowStart="$source_window_start ?? null"
                        :windowEnd="$source_window_end ?? null" />
                @endif
                <span style="font-size:0.6rem; color:#7a7a82;">window:</span>
                @foreach (['today', 'last_24h', 'last_7d'] as $w)
                    @php $active = ($w === $window); @endphp
                    <a href="?window={{ $w }}@if($digestDate)&date={{ $digestDate }}@endif"
                       style="font-size:0.65rem; padding:3px 8px; border-radius:4px; text-decoration:none;
                              background:{{ $active ? 'rgba(125,211,252,0.15)' : 'rgba(255,255,255,0.04)' }};
                              color:{{ $active ? '#7dd3fc' : '#9ca3af' }};">{{ str_replace('_', ' ', $w) }}</a>
                @endforeach
            </div>
            @if (! empty($available_dates) && count($available_dates) > 0)
                <div style="margin-top:0.5rem; font-size:0.65rem; color:#7a7a82;">
                    archive:
                    @foreach ($available_dates as $d)
                        <a href="?window={{ $window }}&date={{ $d }}" style="color:#9ca3af; text-decoration:none; margin-right:0.4rem;">{{ $d }}</a>
                    @endforeach
                </div>
            @endif
        </div>

        @if (! empty($no_digest))
            <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p style="font-size:0.85rem; color:#cbd5e1;">No digest yet for this window. Run:</p>
                <pre style="margin-top:0.5rem; padding:0.5rem 0.75rem; background:rgba(255,255,255,0.03); border-radius:4px; font-size:0.7rem; color:#e5e5e7;">make ci-phase47-daily-digest VIEWER_BLOC={{ $bloc_id }} CI_ARGS="--window {{ $window }}"</pre>
            </div>
        @else
            @php
                $confTierColor = function (?string $tier): string {
                    return match($tier) {
                        'high' => '#86efac',
                        'medium' => '#7dd3fc',
                        'low' => '#fde68a',
                        'insufficient' => '#fca5a5',
                        default => '#9ca3af',
                    };
                };
                $confBadge = function (string $sectionKey) use ($section_confidence, $confTierColor) {
                    $c = $section_confidence[$sectionKey] ?? null;
                    if (! is_array($c)) return '';
                    $tier = $c['tier'] ?? '—';
                    $score = $c['score'] ?? 0.0;
                    $col = $confTierColor($tier);
                    return '<span style="font-size:0.55rem; color:'.$col.'; padding:1px 6px; border-radius:3px; background:rgba(255,255,255,0.04); text-transform:uppercase; letter-spacing:0.06em;">conf '.$tier.' · '.number_format((float) $score, 2).'</span>';
                };
            @endphp

            {{-- Source reliability strip --}}
            @if (! empty($source_reliability))
                <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3" style="border-left:3px solid #c4b5fd;">
                    <div style="display:flex; gap:0.6rem; align-items:center; flex-wrap:wrap; font-size:0.65rem; color:#cbd5e1;">
                        <span style="font-size:0.55rem; color:#c4b5fd; text-transform:uppercase; letter-spacing:0.08em;">Source reliability</span>
                        @if (isset($source_reliability['avg_reporter_reliability']))
                            <span>avg reporter reliability: <strong>{{ number_format((float) $source_reliability['avg_reporter_reliability'], 3) }}</strong></span>
                        @endif
                        @if (isset($source_reliability['reporter_count']))
                            <span>reporters: <strong>{{ $source_reliability['reporter_count'] }}</strong></span>
                        @endif
                        <span style="margin-left:auto; color:#7a7a82; font-style:italic;">narratives are summaries, not certainty — verify before acting</span>
                    </div>
                </div>
            @endif

            {{-- Top metric strip --}}
            <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:0.5rem; margin-bottom:0.75rem;">
                @php
                    $sd = $severity_summary ?: [];
                    $cards = [
                        ['Incidents', array_sum($sd), '#86efac'],
                        ['Strategic+', ($sd['strategic'] ?? 0) + ($sd['escalation'] ?? 0) + ($sd['coalition_level'] ?? 0), '#fdba74'],
                        ['Doctrine events', $metric_summary['doctrine_event_count'] ?? 0, '#c4b5fd'],
                        ['New corridors', $metric_summary['new_corridor_count'] ?? 0, '#7dd3fc'],
                    ];
                @endphp
                @foreach ($cards as [$label, $val, $col])
                    <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="text-align:center;">
                        <div style="font-size:1.4rem; color:{{ $col }};">{{ number_format($val) }}</div>
                        <div style="font-size:0.6rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">{{ $label }}</div>
                    </div>
                @endforeach
            </div>

            {{-- Narrative --}}
            <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
                <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">Brief</h3>
                <div class="aegis-md" style="font-size:0.85rem; color:#e2e8f0; line-height:1.6;">
                    {!! \Illuminate\Support\Str::markdown((string) $narrative_md) !!}
                </div>
            </div>

            <div style="display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:1rem;">
                <div style="display:grid; gap:0.75rem;">
                    {{-- Coalition movement --}}
                    @if (count($coalition_movement) > 0)
                        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                            <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem; display:flex; gap:0.4rem; align-items:center;">Coalition movement {!! $confBadge('coalition_movement') !!}</h3>
                            <table style="width:100%; font-size:0.7rem; color:#cbd5e1; border-collapse:collapse;">
                                <thead style="color:#7a7a82;">
                                    <tr><th style="text-align:left; padding:2px 4px;">bloc</th><th style="text-align:right; padding:2px 4px;">incidents</th><th style="text-align:right; padding:2px 4px;">esc</th><th style="text-align:right; padding:2px 4px;">avg dscan</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($coalition_movement as $c)
                                        <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                            <td style="padding:3px 4px;">{{ $c['bloc_name'] ?? $c['bloc_code'] }}</td>
                                            <td style="text-align:right; padding:3px 4px;">{{ (int) $c['incident_count'] }}</td>
                                            <td style="text-align:right; padding:3px 4px; color:{{ ((int)($c['escalations'] ?? 0)) > 0 ? '#fca5a5' : '#7a7a82' }};">{{ (int) ($c['escalations'] ?? 0) }}</td>
                                            <td style="text-align:right; padding:3px 4px;">{{ number_format((float) ($c['avg_dscan_ships'] ?? 0), 0) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    {{-- Doctrine evolution --}}
                    @if (count($doctrine_evolution) > 0)
                        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                            <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem; display:flex; gap:0.4rem; align-items:center;">Doctrine evolution {!! $confBadge('doctrine_evolution') !!}</h3>
                            <div style="display:grid; gap:0.25rem;">
                                @foreach ($doctrine_evolution as $d)
                                    @php $col = match($d['event_type']) { 'adoption' => '#86efac', 'abandonment' => '#fca5a5', 'sudden_increase' => '#fdba74', 'sudden_decrease' => '#fde68a', 'capital_emergence' => '#fb7185', default => '#9ca3af' }; @endphp
                                    <div style="font-size:0.7rem; padding:0.25rem 0.4rem; background:rgba(255,255,255,0.02); border-radius:4px; border-left:3px solid {{ $col }};">
                                        <span style="color:{{ $col }}; text-transform:uppercase; letter-spacing:0.06em; font-size:0.55rem;">{{ str_replace('_', ' ', $d['event_type']) }}</span>
                                        <span style="color:#cbd5e1;">{{ $d['alliance_name'] ?? '(unattributed)' }}</span>
                                        @if (! empty($d['doctrine_name']))
                                            · <strong>{{ $d['doctrine_name'] }}</strong>
                                        @endif
                                        <span style="color:#7a7a82;"> · Δ {{ number_format((float) $d['magnitude'], 2) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- New corridors --}}
                    @if (count($new_corridors) > 0)
                        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                            <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem; display:flex; gap:0.4rem; align-items:center;">New corridors {!! $confBadge('new_corridors') !!}</h3>
                            <table style="width:100%; font-size:0.7rem; color:#cbd5e1; border-collapse:collapse;">
                                <thead style="color:#7a7a82;">
                                    <tr><th style="text-align:left;">route</th><th style="text-align:right;">tx</th><th style="text-align:right;">chars</th><th style="text-align:left;">class</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($new_corridors as $c)
                                        <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                            <td style="padding:3px 4px;">{{ $c['from_system_name'] }} → {{ $c['to_system_name'] }}</td>
                                            <td style="text-align:right; padding:3px 4px;">{{ $c['transition_count'] }}</td>
                                            <td style="text-align:right; padding:3px 4px;">{{ $c['distinct_characters'] }}</td>
                                            <td style="padding:3px 4px; color:#a5b4fc;">{{ $c['route_classification'] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div style="display:grid; gap:0.75rem;">
                    {{-- Top threat systems --}}
                    @if (count($top_threats) > 0)
                        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                            <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem; display:flex; gap:0.4rem; align-items:center;">Top threat systems {!! $confBadge('top_incidents') !!}</h3>
                            <table style="width:100%; font-size:0.7rem; color:#cbd5e1; border-collapse:collapse;">
                                <thead style="color:#7a7a82;">
                                    <tr><th style="text-align:left;">system</th><th style="text-align:left;">tier</th><th style="text-align:right;">score</th><th style="text-align:right;">cap</th><th style="text-align:right;">doc</th><th style="text-align:left;">mob</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($top_threats as $t)
                                        @php $tcol = match($t['tier']) { 'strategic' => '#fb7185', 'hot' => '#fdba74', 'contested' => '#fde68a', 'watch' => '#86efac', default => '#9ca3af' }; @endphp
                                        <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                            <td style="padding:3px 4px;"><a href="/portal/operations/heatmap?system={{ $t['solar_system_name'] ?? '' }}" style="color:#86efac; text-decoration:none;">{{ $t['solar_system_name'] }}</a></td>
                                            <td style="padding:3px 4px; color:{{ $tcol }};">{{ $t['tier'] }}</td>
                                            <td style="text-align:right; padding:3px 4px;">{{ number_format((float) $t['threat_score'], 2) }}</td>
                                            <td style="text-align:right; padding:3px 4px;">{{ number_format((float) $t['capital_score'], 1) }}</td>
                                            <td style="text-align:right; padding:3px 4px;">{{ number_format((float) $t['doctrine_threat_score'], 1) }}</td>
                                            <td style="padding:3px 4px;">{{ $t['mobility_profile'] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    {{-- Unusual compositions --}}
                    @if (count($unusual_compositions) > 0)
                        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                            <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem; display:flex; gap:0.4rem; align-items:center;">Unusual force compositions {!! $confBadge('unusual_compositions') !!}</h3>
                            <div style="display:grid; gap:0.25rem;">
                                @foreach ($unusual_compositions as $f)
                                    <div style="font-size:0.7rem; padding:0.3rem 0.4rem; background:rgba(255,255,255,0.02); border-radius:4px;">
                                        <span style="color:#86efac;">{{ $f['primary_system_name'] ?? '?' }}</span>
                                        · <strong style="color:#fdba74;">{{ $f['ship_total'] }} ships</strong>
                                        @if ((int)($f['estimated_super_count'] ?? 0) > 0 || (int)($f['estimated_capital_count'] ?? 0) > 0)
                                            · <span style="color:#fca5a5;">{{ $f['estimated_capital_count'] }} cap / {{ $f['estimated_super_count'] }} super</span>
                                        @endif
                                        @if (! empty($f['primary_doctrine_name']))
                                            · <span style="color:#c4b5fd;">{{ $f['primary_doctrine_name'] }}</span>
                                        @else
                                            · <span style="color:#fde68a;">off-meta</span>
                                        @endif
                                        <div style="color:#7a7a82; font-size:0.6rem; margin-top:0.1rem;">{{ $f['snapshot_at'] }} · {{ $f['brawl_range'] ?? '?' }} · {{ $f['mobility'] ?? '?' }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Emerging operators --}}
                    @if (count($emerging_operators) > 0)
                        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                            <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem; display:flex; gap:0.4rem; align-items:center;">Emerging operators {!! $confBadge('emerging_operators') !!}</h3>
                            <div style="display:grid; gap:0.2rem;">
                                @foreach ($emerging_operators as $o)
                                    <a href="/portal/characters/lookup?cid={{ $o['character_id'] }}" style="display:flex; gap:0.4rem; align-items:center; padding:0.25rem 0.4rem; background:rgba(255,255,255,0.02); border-radius:4px; text-decoration:none; color:#e5e5e7; font-size:0.7rem;">
                                        <img src="https://images.evetech.net/characters/{{ $o['character_id'] }}/portrait?size=32" style="width:18px; height:18px; border-radius:50%;" alt="">
                                        <span style="flex:1;">{{ $o['character_name'] ?? '#'.$o['character_id'] }}</span>
                                        <span style="color:#a5b4fc; font-size:0.6rem;">{{ str_replace('_', ' ', $o['primary_style']) }}</span>
                                        <span style="color:#7a7a82; font-size:0.55rem;">{{ $o['cluster_appearances'] }}×</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Top incidents (clickable) --}}
            @if (count($top_incident_ids) > 0)
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="margin-top:1rem;">
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">Top incidents</h3>
                    <div style="display:flex; gap:0.4rem; flex-wrap:wrap;">
                        @foreach ($top_incident_ids as $iid)
                            <a href="/portal/operations/incidents/{{ $iid }}" style="font-size:0.65rem; padding:3px 8px; border-radius:4px; background:rgba(167,139,250,0.10); color:#c4b5fd; text-decoration:none;">#{{ $iid }} →</a>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
    @endif
</x-filament-panels::page>

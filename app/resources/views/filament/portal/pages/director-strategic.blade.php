<x-filament-panels::page>
    @if (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">No bloc resolved.</p>
        </div>
    @else
        @php
            $tierColors = ['safe' => '#86efac', 'watch' => '#fde68a', 'contested' => '#fdba74', 'hot' => '#fb923c', 'strategic' => '#fb7185'];
            $sevColors = ['noise' => '#6b7280', 'tactical' => '#fde68a', 'strategic' => '#fdba74', 'escalation' => '#fca5a5', 'coalition_level' => '#d8b4fe'];

            // Pivot escalation trend into a date->severity matrix.
            $byDay = [];
            foreach ($escalation_trend as $r) {
                $d = (string) $r->d;
                $byDay[$d][$r->severity] = (int) $r->n;
            }
            ksort($byDay);
        @endphp

        {{-- Verdict banner --}}
        <x-verdict-banner :verdict="$verdict ?? null" />

        {{-- Window switcher --}}
        <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
            <div style="display:flex; gap:0.5rem; align-items:center; font-size:0.7rem;">
                <span style="color:#7a7a82;">window:</span>
                @foreach ([7, 14, 30, 60, 90] as $d)
                    @php $a = $d === $days; @endphp
                    <a href="?days={{ $d }}" style="padding:3px 8px; border-radius:4px; text-decoration:none;
                       background:{{ $a ? 'rgba(125,211,252,0.15)' : 'rgba(255,255,255,0.04)' }};
                       color:{{ $a ? '#7dd3fc' : '#9ca3af' }};">{{ $d }}d</a>
                @endforeach
                @if ($latest_profile_end)
                    <span style="margin-left:auto; color:#7a7a82;">profile window: {{ $latest_profile_end }}</span>
                    <x-intel-freshness surface="alliance_profile"
                        :timestamp="$latest_profile_end" />
                @endif
            </div>
        </div>

        {{-- Heat-tier strip --}}
        @if (count($heat_tiers) > 0)
            <div style="display:grid; grid-template-columns:repeat(5, 1fr); gap:0.4rem; margin-bottom:0.75rem;">
                @foreach (['safe', 'watch', 'contested', 'hot', 'strategic'] as $tier)
                    @php $col = $tierColors[$tier]; $n = $heat_tiers[$tier] ?? 0; @endphp
                    <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="text-align:center; border-top:3px solid {{ $col }};">
                        <div style="font-size:1.3rem; color:{{ $col }};">{{ $n }}</div>
                        <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">{{ $tier }}</div>
                    </div>
                @endforeach
            </div>
        @endif

        <div style="display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:1rem;">
            <div style="display:grid; gap:0.75rem;">
                {{-- Coalitions --}}
                @if (count($coalitions) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                            Coalitions ({{ count($coalitions) }})
                        </h3>
                        <table style="width:100%; font-size:0.7rem; color:#cbd5e1; border-collapse:collapse;">
                            <thead style="color:#7a7a82;">
                                <tr><th style="text-align:left;">bloc</th><th style="text-align:right;">alliances</th><th style="text-align:right;">incidents</th><th style="text-align:right;">esc rate</th><th style="text-align:right;">avg fleet</th><th style="text-align:right;">cap rate</th><th style="text-align:right;">doc div</th><th style="text-align:left;">mob</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($coalitions as $c)
                                    <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                        <td style="padding:3px 4px;"><strong>{{ $c->bloc_display_name ?? $c->bloc_code }}</strong>
                                            <x-intel-freshness surface="coalition"
                                                :timestamp="$c->computed_at ?? $c->updated_at ?? null"
                                                :persisted="$c->freshness_state ?? null" /></td>
                                        <td style="text-align:right; padding:3px 4px;">{{ $c->alliance_count }}</td>
                                        <td style="text-align:right; padding:3px 4px;">{{ number_format($c->incident_count) }}</td>
                                        <td style="text-align:right; padding:3px 4px; color:{{ (float)$c->escalation_rate > 0.05 ? '#fca5a5' : '#cbd5e1' }};">{{ number_format(((float) $c->escalation_rate) * 100, 1) }}%</td>
                                        <td style="text-align:right; padding:3px 4px;">{{ $c->avg_fleet_size ? number_format((float)$c->avg_fleet_size, 0) : '—' }}</td>
                                        <td style="text-align:right; padding:3px 4px;">{{ number_format(((float)$c->capital_usage_rate) * 100, 1) }}%</td>
                                        <td style="text-align:right; padding:3px 4px;">{{ number_format((float)$c->doctrine_diversity, 2) }}</td>
                                        <td style="padding:3px 4px;">{{ $c->primary_mobility ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                {{-- Top alliances by incident_count --}}
                @if (count($alliances) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                            Alliance operational profiles · top {{ count($alliances) }}
                        </h3>
                        <div style="max-height:480px; overflow:auto;">
                            <table style="width:100%; font-size:0.7rem; color:#cbd5e1; border-collapse:collapse;">
                                <thead style="color:#7a7a82; position:sticky; top:0; background:#0f1117;">
                                    <tr><th style="text-align:left;">alliance</th><th style="text-align:left;">style</th><th style="text-align:right;">inc</th><th style="text-align:right;">comps</th><th style="text-align:right;">avg fleet</th><th style="text-align:right;">avg cap</th><th style="text-align:left;">brawl</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($alliances as $a)
                                        <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                            <td style="padding:3px 4px;">{{ $a->alliance_name ?? '#'.$a->alliance_id }}</td>
                                            <td style="padding:3px 4px; color:#a5b4fc;">{{ str_replace('_', ' ', $a->operational_style) }}</td>
                                            <td style="text-align:right; padding:3px 4px;">{{ $a->incident_count }}</td>
                                            <td style="text-align:right; padding:3px 4px;">{{ $a->composition_count }}</td>
                                            <td style="text-align:right; padding:3px 4px;">{{ $a->avg_fleet_size ? number_format((float)$a->avg_fleet_size, 0) : '—' }}</td>
                                            <td style="text-align:right; padding:3px 4px; color:{{ (float)$a->avg_capital_presence > 0 ? '#fca5a5' : '#cbd5e1' }};">{{ number_format((float)$a->avg_capital_presence, 2) }}</td>
                                            <td style="padding:3px 4px;">{{ $a->primary_brawl_range ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>

            <div style="display:grid; gap:0.75rem;">
                {{-- Escalation timeline --}}
                @if (count($byDay) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                            Severity trend · {{ $days }}d
                        </h3>
                        <div style="display:flex; gap:1px; align-items:flex-end; height:80px;">
                            @php
                                $maxN = 1;
                                foreach ($byDay as $d => $row) { $maxN = max($maxN, array_sum($row)); }
                            @endphp
                            @foreach ($byDay as $d => $row)
                                @php $total = array_sum($row); $h = max(2, (int) (($total / $maxN) * 80)); @endphp
                                <div title="{{ $d }} · {{ $total }} incidents" style="flex:1; height:{{ $h }}px; display:flex; flex-direction:column; justify-content:flex-end;">
                                    @foreach (['noise', 'tactical', 'strategic', 'escalation', 'coalition_level'] as $sev)
                                        @php $sn = $row[$sev] ?? 0; if ($sn === 0) continue; $sh = max(1, (int) (($sn / $total) * $h)); @endphp
                                        <div style="background:{{ $sevColors[$sev] }}; height:{{ $sh }}px;"></div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                        <div style="display:flex; gap:0.5rem; margin-top:0.4rem; font-size:0.55rem; color:#7a7a82; flex-wrap:wrap;">
                            @foreach ($sevColors as $sev => $col)
                                <span><span style="display:inline-block; width:8px; height:8px; background:{{ $col }};"></span> {{ $sev }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Doctrine events --}}
                @if (count($doctrine_events) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                            Doctrine evolution ({{ count($doctrine_events) }})
                        </h3>
                        <div style="display:grid; gap:0.2rem; max-height:340px; overflow:auto;">
                            @foreach ($doctrine_events as $d)
                                @php $col = match($d->event_type) { 'adoption' => '#86efac', 'abandonment' => '#fca5a5', 'sudden_increase' => '#fdba74', 'sudden_decrease' => '#fde68a', 'capital_emergence' => '#fb7185', default => '#9ca3af' }; @endphp
                                <div style="font-size:0.7rem; padding:0.25rem 0.4rem; background:rgba(255,255,255,0.02); border-left:3px solid {{ $col }}; border-radius:4px;">
                                    <div style="display:flex; gap:0.4rem; align-items:center;">
                                        <span style="font-size:0.55rem; color:{{ $col }}; text-transform:uppercase;">{{ str_replace('_', ' ', $d->event_type) }}</span>
                                        <span style="color:#cbd5e1; flex:1;">{{ $d->alliance_name ?? '(unattributed)' }}</span>
                                        <x-intel-freshness surface="doctrine_evolution"
                                            :timestamp="$d->computed_at ?? null"
                                            :persisted="$d->freshness_state ?? null" />
                                        <span style="color:#7a7a82;">{{ $d->window_end }}</span>
                                    </div>
                                    @if ($d->doctrine_name)
                                        <div style="color:#c4b5fd; margin-top:0.1rem;">{{ $d->doctrine_name }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Deployments --}}
                @if (count($deployments) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                            Deployment migrations · top {{ count($deployments) }}
                        </h3>
                        <div style="display:grid; gap:0.15rem;">
                            @foreach ($deployments as $d)
                                <div style="font-size:0.7rem; padding:0.2rem 0.4rem; background:rgba(255,255,255,0.02); border-radius:4px; display:flex; gap:0.3rem;">
                                    <span style="color:#86efac; flex:1;">{{ $d->from_system_name }} → {{ $d->to_system_name }}</span>
                                    <span>{{ $d->transition_count }}</span>
                                    <span style="color:#7a7a82; font-size:0.55rem;">{{ $d->distinct_characters }} chars</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</x-filament-panels::page>

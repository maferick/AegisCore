<x-filament-panels::page>
    @if (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">No bloc resolved.</p>
        </div>
    @else
        @php
            $laneStateColors = [
                'healthy' => '#86efac', 'degraded' => '#fde68a',
                'backlogged' => '#fdba74', 'starved' => '#fb923c',
                'failed' => '#fb7185',
                'not_instrumented' => '#7a7a82',
            ];
            $surfaceStateColors = [
                'healthy' => '#86efac', 'degraded' => '#fde68a',
                'stale' => '#fdba74', 'backlogged' => '#fb923c',
                'failed' => '#fb7185',
            ];
            $sevColors = ['critical' => '#fb7185', 'elevated' => '#fdba74',
                          'warning' => '#fde68a', 'info' => '#9ca3af'];
            $statusColors = ['running' => '#7dd3fc', 'succeeded' => '#86efac',
                             'failed' => '#fb7185', 'aborted' => '#9ca3af'];
        @endphp

        {{-- Top pulse strip --}}
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:0.5rem; margin-bottom:0.75rem;">
            <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="text-align:center;">
                <div style="font-size:1.4rem; color:#86efac;">{{ number_format($ingest_pulse['n_24h']) }}</div>
                <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">events 24h</div>
            </div>
            <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="text-align:center;">
                <div style="font-size:1.4rem; color:{{ $parser_pulse['error_rate'] >= 0.05 ? '#fb7185' : '#86efac' }};">{{ number_format(($parser_pulse['error_rate'] ?? 0) * 100, 2) }}%</div>
                <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">parse error rate</div>
                <div style="font-size:0.55rem; color:#7a7a82;">{{ $parser_pulse['errors_24h'] }} / {{ $parser_pulse['events_24h'] }} 24h</div>
            </div>
            <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="text-align:center;">
                <div style="font-size:1.4rem; color:{{ $parser_pulse['unknown_rate'] >= 0.08 ? '#fdba74' : '#86efac' }};">{{ number_format(($parser_pulse['unknown_rate'] ?? 0) * 100, 2) }}%</div>
                <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">unknown event rate</div>
            </div>
            <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="text-align:center;">
                <div style="font-size:1.4rem; color:#fdba74;">{{ number_format($alert_pulse) }}</div>
                <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">alerts 24h</div>
            </div>
            <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="text-align:center;">
                <div style="font-size:1.4rem; color:#a5b4fc;">{{ number_format($incident_pulse) }}</div>
                <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">incidents 24h</div>
            </div>
        </div>

        <div style="display:grid; grid-template-columns:minmax(0,1.4fr) minmax(0,1fr); gap:1rem;">
            <div style="display:grid; gap:0.75rem;">
                {{-- Compute lanes --}}
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">Compute lanes</h3>
                    @if (count($lanes) === 0)
                        <p style="font-size:0.75rem; color:#7a7a82; font-style:italic;">No lane metrics yet — run <code>make ci-phase49a-lane-metrics</code>.</p>
                    @else
                        <table style="width:100%; font-size:0.7rem; color:#cbd5e1; border-collapse:collapse;">
                            <thead style="color:#7a7a82;">
                                <tr><th style="text-align:left;">lane</th><th style="text-align:left;">state</th><th style="text-align:right;">running</th><th style="text-align:right;">succ 24h</th><th style="text-align:right;">fail 24h</th><th style="text-align:right;">retries</th><th style="text-align:right;">avg ms</th><th style="text-align:right;">p95 ms</th><th style="text-align:right;">oldest pend</th><th style="text-align:right;">tput/h</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($lanes as $l)
                                    @php
                                        $col = $laneStateColors[$l->lane_state] ?? '#9ca3af';
                                        $notInstrumented = ($l->lane_state === 'not_instrumented');
                                    @endphp
                                    <tr style="border-top:1px solid rgba(255,255,255,0.05);{{ $notInstrumented ? ' opacity:0.55;' : '' }}">
                                        <td style="padding:3px 4px;"><strong>{{ str_replace('_', ' ', $l->lane) }}</strong></td>
                                        <td style="padding:3px 4px; color:{{ $col }};">{{ str_replace('_', ' ', $l->lane_state) }}</td>
                                        @if ($notInstrumented)
                                            <td colspan="8" style="padding:3px 4px; text-align:left; color:#7a7a82; font-style:italic;">no instrumented pipelines reporting · expected for ingest/parser/graph until ComputeLog wraps the relevant CLI entries</td>
                                        @else
                                            @php
                                                $r = $lane_retry[$l->lane] ?? ['retries' => 0, 'retried_runs' => 0, 'runs_24h' => 0, 'open_circuits' => 0];
                                                $retryColor = $r['open_circuits'] > 0 ? '#fb7185' : ($r['retries'] > 0 ? '#fdba74' : '#7a7a82');
                                            @endphp
                                            <td style="padding:3px 4px; text-align:right; color:{{ $l->running_jobs > 0 ? '#7dd3fc' : '#7a7a82' }};">{{ $l->running_jobs }}</td>
                                            <td style="padding:3px 4px; text-align:right;">{{ $l->succeeded_24h }}</td>
                                            <td style="padding:3px 4px; text-align:right; color:{{ $l->failed_24h > 0 ? '#fb7185' : '#7a7a82' }};">{{ $l->failed_24h }}</td>
                                            <td style="padding:3px 4px; text-align:right; color:{{ $retryColor }};">
                                                {{ $r['retries'] }}@if ($r['retried_runs'] > 0) <span style="color:#7a7a82;">/ {{ $r['retried_runs'] }} runs</span>@endif
                                                @if ($r['open_circuits'] > 0)
                                                    <span style="margin-left:0.3rem; padding:1px 4px; border-radius:3px; background:rgba(251,113,133,0.15); color:#fb7185; font-size:0.5rem; text-transform:uppercase;">⚡ {{ $r['open_circuits'] }} circuit{{ $r['open_circuits'] === 1 ? '' : 's' }}</span>
                                                @endif
                                            </td>
                                            <td style="padding:3px 4px; text-align:right;">{{ $l->avg_duration_ms ?? '—' }}</td>
                                            <td style="padding:3px 4px; text-align:right;">{{ $l->p95_duration_ms ?? '—' }}</td>
                                            <td style="padding:3px 4px; text-align:right;">{{ $l->oldest_pending_seconds ? floor($l->oldest_pending_seconds / 60).'m' : '—' }}</td>
                                            <td style="padding:3px 4px; text-align:right;">{{ number_format((float) $l->throughput_per_hour, 2) }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>

                {{-- Surface health --}}
                @if (count($surface_freshness) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">Surface health</h3>
                        <table style="width:100%; font-size:0.7rem; color:#cbd5e1; border-collapse:collapse;">
                            <thead style="color:#7a7a82;">
                                <tr><th style="text-align:left;">surface</th><th style="text-align:left;">badge</th><th style="text-align:right;">total</th><th style="text-align:right; color:#86efac;">fresh</th><th style="text-align:right; color:#fde68a;">aging</th><th style="text-align:right; color:#fdba74;">stale</th><th style="text-align:right; color:#fb7185;">expired</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($surface_freshness as $surface => $tally)
                                    @php $health = $surface_health[$surface] ?? 'healthy'; $col = $surfaceStateColors[$health] ?? '#9ca3af'; @endphp
                                    <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                        <td style="padding:3px 4px;">{{ str_replace('_', ' ', $surface) }}</td>
                                        <td style="padding:3px 4px;"><span style="font-size:0.55rem; padding:1px 6px; border-radius:3px; background:rgba(255,255,255,0.04); color:{{ $col }};">{{ $health }}</span></td>
                                        <td style="padding:3px 4px; text-align:right;">{{ number_format($tally['total']) }}</td>
                                        <td style="padding:3px 4px; text-align:right; color:#86efac;">{{ $tally['fresh'] ?? 0 }}</td>
                                        <td style="padding:3px 4px; text-align:right; color:#fde68a;">{{ $tally['aging'] ?? 0 }}</td>
                                        <td style="padding:3px 4px; text-align:right; color:#fdba74;">{{ $tally['stale'] ?? 0 }}</td>
                                        <td style="padding:3px 4px; text-align:right; color:#fb7185;">{{ $tally['expired'] ?? 0 }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                {{-- Recent runs --}}
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">Recent compute runs ({{ count($recent_runs) }})</h3>
                    @if (count($recent_runs) === 0)
                        <p style="font-size:0.75rem; color:#7a7a82; font-style:italic;">No instrumented runs yet.</p>
                    @else
                        <div style="max-height:380px; overflow:auto;">
                            <table style="width:100%; font-size:0.65rem; color:#cbd5e1; border-collapse:collapse;">
                                <thead style="color:#7a7a82; position:sticky; top:0; background:#0f1117;">
                                    <tr><th style="text-align:left;">started</th><th style="text-align:left;">lane</th><th style="text-align:left;">pipeline</th><th style="text-align:left;">status</th><th style="text-align:right;">dur ms</th><th style="text-align:right;">in→out</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($recent_runs as $r)
                                        @php $sCol = $statusColors[$r->status] ?? '#9ca3af'; @endphp
                                        <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                            <td style="padding:2px 4px; color:#9ca3af; font-family:ui-monospace,monospace;">{{ $r->compute_started_at }}</td>
                                            <td style="padding:2px 4px;">{{ str_replace('_', ' ', $r->lane) }}</td>
                                            <td style="padding:2px 4px;">{{ $r->pipeline }}</td>
                                            <td style="padding:2px 4px; color:{{ $sCol }};">{{ $r->status }}</td>
                                            <td style="padding:2px 4px; text-align:right;">{{ $r->compute_duration_ms ?? '—' }}</td>
                                            <td style="padding:2px 4px; text-align:right; color:#7a7a82;">{{ $r->source_row_count ?? '—' }}→{{ $r->generated_row_count ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            <div style="display:grid; gap:0.75rem;">
                {{-- Open quality events --}}
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">Open quality events ({{ count($quality_events) }})</h3>
                    @if (count($quality_events) === 0)
                        <p style="font-size:0.75rem; color:#7a7a82; font-style:italic;">All clear.</p>
                    @else
                        <div style="display:grid; gap:0.3rem;">
                            @foreach ($quality_events as $e)
                                @php $col = $sevColors[$e->severity] ?? '#9ca3af'; @endphp
                                <div style="padding:0.4rem 0.55rem; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-left:3px solid {{ $col }}; border-radius:5px;">
                                    <div style="display:flex; gap:0.3rem; align-items:center; flex-wrap:wrap;">
                                        <span style="font-size:0.55rem; color:{{ $col }}; text-transform:uppercase;">{{ $e->severity }}</span>
                                        <span style="font-size:0.55rem; color:#a5b4fc; text-transform:uppercase;">{{ str_replace('_', ' ', $e->detector) }}</span>
                                        <span style="font-size:0.75rem; color:#e5e5e7; flex:1;">{{ $e->title }}</span>
                                    </div>
                                    @if ($e->summary)
                                        <div style="font-size:0.65rem; color:#cbd5e1; margin-top:0.2rem;">{{ $e->summary }}</div>
                                    @endif
                                    <div style="margin-top:0.3rem; font-size:0.55rem; color:#7a7a82; display:flex; gap:0.4rem;">
                                        <span>{{ $e->detected_at }}</span>
                                        @if ($e->metric_value !== null && $e->threshold_value !== null)
                                            <span>· metric {{ rtrim(rtrim((string) $e->metric_value, '0'), '.') }} vs threshold {{ rtrim(rtrim((string) $e->threshold_value, '0'), '.') }}</span>
                                        @endif
                                        <span style="margin-left:auto; display:flex; gap:0.25rem;">
                                            @if (! $e->acknowledged_at)
                                                <button wire:click="ackQualityEvent({{ $e->id }})" style="font-size:0.5rem; padding:1px 5px; border-radius:3px; background:rgba(125,211,252,0.10); color:#7dd3fc; border:none; cursor:pointer;">ack</button>
                                            @else
                                                <span style="color:#86efac;">acked</span>
                                            @endif
                                            <button wire:click="resolveQualityEvent({{ $e->id }})" style="font-size:0.5rem; padding:1px 5px; border-radius:3px; background:rgba(134,239,172,0.10); color:#86efac; border:none; cursor:pointer;">resolve</button>
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Top retry pipelines (24h) --}}
                @if (count($top_retry_pipelines) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">Top retry pipelines · 24h</h3>
                        <table style="width:100%; font-size:0.65rem; color:#cbd5e1; border-collapse:collapse;">
                            <thead style="color:#7a7a82;">
                                <tr><th style="text-align:left;">pipeline</th><th style="text-align:left;">reason</th><th style="text-align:right;">retries</th><th style="text-align:right;">runs</th><th style="text-align:right;">success %</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($top_retry_pipelines as $r)
                                    @php
                                        $total = (int)$r->retried_runs;
                                        $succ = (int)($r->succeeded_after_retry ?? 0);
                                        $rate = $total > 0 ? ($succ / $total) : 0;
                                        $rateCol = $rate >= 0.9 ? '#86efac' : ($rate >= 0.5 ? '#fde68a' : '#fb7185');
                                        $reasonCol = match($r->retry_reason) {
                                            'transient' => '#7dd3fc',
                                            'contention' => '#fdba74',
                                            'rate_limit' => '#c4b5fd',
                                            'permanent' => '#fb7185',
                                            'malformed_input' => '#fb7185',
                                            default => '#9ca3af',
                                        };
                                    @endphp
                                    <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                        <td style="padding:2px 4px;">{{ $r->pipeline }}</td>
                                        <td style="padding:2px 4px; color:{{ $reasonCol }};">{{ $r->retry_reason }}</td>
                                        <td style="padding:2px 4px; text-align:right;">{{ $r->total_retries }}</td>
                                        <td style="padding:2px 4px; text-align:right;">{{ $r->retried_runs }}</td>
                                        <td style="padding:2px 4px; text-align:right; color:{{ $rateCol }};">{{ number_format($rate * 100, 0) }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                {{-- Open circuits --}}
                @if (count($open_circuits) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="border-left:3px solid #fb7185;">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#fb7185; margin:0 0 0.4rem;">⚡ Open circuits ({{ count($open_circuits) }})</h3>
                        <div style="display:grid; gap:0.25rem; font-size:0.7rem;">
                            @foreach ($open_circuits as $cir)
                                @php $stCol = $cir->state === 'half_open' ? '#fde68a' : '#fb7185'; @endphp
                                <div style="padding:0.25rem 0.4rem; background:rgba(251,113,133,0.06); border-radius:4px;">
                                    <div style="display:flex; gap:0.4rem; align-items:center;">
                                        <span style="font-size:0.55rem; color:{{ $stCol }}; text-transform:uppercase;">{{ str_replace('_', ' ', $cir->state) }}</span>
                                        <strong style="color:#fca5a5;">{{ str_replace('_', ' ', $cir->lane) }} / {{ $cir->pipeline }}</strong>
                                    </div>
                                    <div style="color:#7a7a82; font-size:0.6rem; margin-top:0.15rem;">
                                        {{ $cir->consecutive_failures }} consecutive · last reason: {{ $cir->last_failure_reason }}
                                        @if ($cir->cooldown_until)
                                            · cooldown until {{ $cir->cooldown_until }}
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div style="margin-top:0.4rem; font-size:0.55rem; color:#7a7a82; font-style:italic;">A pipeline reopens automatically when cooldown expires (half-open). Successful run closes the circuit.</div>
                    </div>
                @endif

                {{-- Long-running jobs --}}
                @if (count($running_too_long) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="border-left:3px solid #fb7185;">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#fb7185; margin:0 0 0.4rem;">Long-running jobs ({{ count($running_too_long) }})</h3>
                        <div style="display:grid; gap:0.2rem; font-size:0.7rem;">
                            @foreach ($running_too_long as $r)
                                <div style="padding:0.25rem 0.4rem; background:rgba(251,113,133,0.06); border-radius:4px;">
                                    <strong style="color:#fca5a5;">{{ $r->pipeline }}</strong>
                                    <span style="color:#7a7a82;"> · {{ $r->lane }} · started {{ $r->compute_started_at }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</x-filament-panels::page>

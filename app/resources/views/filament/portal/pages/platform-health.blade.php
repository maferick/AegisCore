<x-filament-panels::page>
    @if (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">No bloc resolved.</p>
        </div>
    @else
        @php
            $verdictColors = [
                'info' => ['bg' => 'rgba(134,239,172,0.10)', 'border' => '#86efac', 'fg' => '#86efac'],
                'warning' => ['bg' => 'rgba(253,230,138,0.10)', 'border' => '#fde68a', 'fg' => '#fde68a'],
                'elevated' => ['bg' => 'rgba(253,186,116,0.12)', 'border' => '#fdba74', 'fg' => '#fdba74'],
                'critical' => ['bg' => 'rgba(251,113,133,0.14)', 'border' => '#fb7185', 'fg' => '#fb7185'],
            ];
            $laneStateColors = [
                'healthy' => '#86efac', 'degraded' => '#fde68a',
                'backlogged' => '#fdba74', 'starved' => '#fb923c',
                'failed' => '#fb7185',
                'not_instrumented' => '#7a7a82',
            ];
            $surfaceStateColors = [
                'healthy' => '#86efac', 'aging' => '#fde68a',
                'stale' => '#fdba74', 'failed' => '#fb7185',
                // legacy ratio-based states kept for backwards-compat
                // until any cached page state turns over.
                'degraded' => '#fde68a', 'backlogged' => '#fb923c',
            ];
            $sevColors = ['critical' => '#fb7185', 'elevated' => '#fdba74',
                          'warning' => '#fde68a', 'info' => '#9ca3af'];
            $statusColors = ['running' => '#7dd3fc', 'succeeded' => '#86efac',
                             'failed' => '#fb7185', 'aborted' => '#9ca3af'];
        @endphp

        {{-- Verdict banner --}}
        <x-verdict-banner :verdict="$verdict" />

        {{-- Top pulse strip --}}
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:0.5rem; margin-bottom:0.75rem;">
            <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="text-align:center;">
                <div style="font-size:1.4rem; color:#86efac;">{{ number_format($ingest_pulse['n_24h']) }}</div>
                <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">events 24h</div>
            </div>
            <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="text-align:center;">
                <div style="font-size:1.4rem; color:{{ $parser_pulse['error_rate'] >= 0.05 ? '#fb7185' : '#86efac' }};">{{ number_format(($parser_pulse['error_rate'] ?? 0) * 100, 2) }}%</div>
                <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">parse error rate (open)</div>
                <div style="font-size:0.55rem; color:#7a7a82;">{{ number_format($parser_pulse['errors_24h']) }} open / {{ number_format($parser_pulse['events_24h']) }} 24h</div>
                @if (! empty($parser_pulse['resolved_24h']))
                    <div style="font-size:0.55rem; color:#86efac;">+{{ number_format($parser_pulse['resolved_24h']) }} resolved</div>
                @endif
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

        @if (! empty($parser_pulse['top_reasons']) && count($parser_pulse['top_reasons']) > 0)
            <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="margin-bottom:0.75rem;">
                <h3 style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.3rem;">Top open parse-error reasons (24h)</h3>
                <table style="width:100%; font-size:0.7rem; color:#cbd5e1; border-collapse:collapse;">
                    <tbody>
                        @foreach ($parser_pulse['top_reasons'] as $r)
                            <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                <td style="padding:3px 4px;"><code>{{ $r->reason }}</code></td>
                                <td style="padding:3px 4px; text-align:right; color:#fb7185;">{{ number_format($r->n) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p style="font-size:0.65rem; color:#cbd5e1; margin:0.4rem 0 0;">
                    Review the queue at
                    <a href="/portal/eve-log/uploader-errors" style="color:#7dd3fc;">Parser Errors</a>.
                </p>
                <details style="margin-top:0.3rem;">
                    <summary style="font-size:0.6rem; color:#7a7a82; cursor:pointer;">admin · replay command</summary>
                    <p style="font-size:0.6rem; color:#7a7a82; margin:0.3rem 0 0;">After fixing parser logic, replay the queue: <code>php artisan eve-log:retry-parse-errors</code>.</p>
                </details>
            </div>
        @endif

        <div style="display:grid; grid-template-columns:minmax(0,1.4fr) minmax(0,1fr); gap:1rem;">
            <div style="display:grid; gap:0.75rem;">
                {{-- Compute lanes --}}
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">Compute lanes</h3>
                    @if (count($lanes) === 0)
                        <p style="font-size:0.75rem; color:#7a7a82; font-style:italic;">No lane metrics yet — run <code>make ci-phase49a-lane-metrics</code>.</p>
                    @else
                        <table style="width:100%; font-size:0.72rem; color:#e2e8f0; border-collapse:separate; border-spacing:0;">
                            <thead style="color:#cbd5e1; background:rgba(255,255,255,0.04);">
                                <tr>
                                    <th style="text-align:left; padding:6px 8px; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.15);">lane</th>
                                    <th style="text-align:left; padding:6px 8px; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.15);">state</th>
                                    <th style="text-align:right; padding:6px 8px; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.15);" title="Pipelines currently in 'running' status">running</th>
                                    <th style="text-align:right; padding:6px 8px; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.15);" title="Successful runs in last 24h">succ 24h</th>
                                    <th style="text-align:right; padding:6px 8px; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.15);" title="Failed runs in last 24h">fail 24h</th>
                                    <th style="text-align:right; padding:6px 8px; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.15);" title="Retry attempts / runs that hit retry / open circuits">retries</th>
                                    <th style="text-align:right; padding:6px 8px; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.15);" title="Average run duration in ms (last 24h)">avg ms</th>
                                    <th style="text-align:right; padding:6px 8px; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.15);" title="95th-percentile run duration in ms — slowest 5% are slower than this">p95 ms</th>
                                    <th style="text-align:right; padding:6px 8px; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.15);" title="Oldest pipeline still in 'running' status (minutes since started)">oldest pend</th>
                                    <th style="text-align:right; padding:6px 8px; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.15);" title="Throughput per hour — runs completed per hour over the window">tput/h</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($instrumented_lanes as $l)
                                    @php
                                        $col = $laneStateColors[$l->lane_state] ?? '#9ca3af';
                                        $r = $lane_retry[$l->lane] ?? ['retries' => 0, 'retried_runs' => 0, 'runs_24h' => 0, 'open_circuits' => 0];
                                        $retryColor = $r['open_circuits'] > 0 ? '#fb7185' : ($r['retries'] > 0 ? '#fdba74' : '#9ca3af');
                                        $stripe = $loop->iteration % 2 === 0 ? 'background:rgba(255,255,255,0.02);' : '';
                                    @endphp
                                    <tr style="border-top:1px solid rgba(255,255,255,0.08); {{ $stripe }}">
                                        <td style="padding:6px 8px;"><strong>{{ str_replace('_', ' ', $l->lane) }}</strong></td>
                                        <td style="padding:6px 8px; color:{{ $col }}; font-weight:600;">{{ str_replace('_', ' ', $l->lane_state) }}</td>
                                        <td style="padding:6px 8px; text-align:right; color:{{ $l->running_jobs > 0 ? '#7dd3fc' : '#9ca3af' }};">{{ $l->running_jobs }}</td>
                                        <td style="padding:6px 8px; text-align:right;">{{ $l->succeeded_24h }}</td>
                                        <td style="padding:6px 8px; text-align:right; color:{{ $l->failed_24h > 0 ? '#fb7185' : '#9ca3af' }};">{{ $l->failed_24h }}</td>
                                        <td style="padding:6px 8px; text-align:right; color:{{ $retryColor }};">
                                            {{ $r['retries'] }}@if ($r['retried_runs'] > 0) <span style="color:#9ca3af;">/ {{ $r['retried_runs'] }} runs</span>@endif
                                            @if ($r['open_circuits'] > 0)
                                                <span style="margin-left:0.3rem; padding:1px 4px; border-radius:3px; background:rgba(251,113,133,0.15); color:#fb7185; font-size:0.55rem; text-transform:uppercase;">⚡ {{ $r['open_circuits'] }} circuit{{ $r['open_circuits'] === 1 ? '' : 's' }}</span>
                                            @endif
                                        </td>
                                        <td style="padding:6px 8px; text-align:right;">{{ $l->avg_duration_ms ?? '—' }}</td>
                                        <td style="padding:6px 8px; text-align:right;">{{ $l->p95_duration_ms ?? '—' }}</td>
                                        <td style="padding:6px 8px; text-align:right;">{{ $l->oldest_pending_seconds ? floor($l->oldest_pending_seconds / 60).'m' : '—' }}</td>
                                        <td style="padding:6px 8px; text-align:right;">{{ number_format((float) $l->throughput_per_hour, 2) }}</td>
                                    </tr>
                                @endforeach
                                @if (count($not_instrumented_lanes) > 0)
                                    <tr style="border-top:1px solid rgba(255,255,255,0.08);">
                                        <td colspan="10" style="padding:6px 8px; color:#9ca3af;">
                                            <details>
                                                <summary style="cursor:pointer;">
                                                    <span style="color:#7a7a82; font-style:italic;">
                                                        {{ count($not_instrumented_lanes) }} lane{{ count($not_instrumented_lanes) === 1 ? '' : 's' }} pending instrumentation
                                                        ({{ collect($not_instrumented_lanes)->pluck('lane')->map(fn ($x) => str_replace('_', ' ', $x))->join(', ') }})
                                                        — expected
                                                    </span>
                                                </summary>
                                                <p style="margin:0.4rem 0 0; font-size:0.65rem; color:#7a7a82;">
                                                    These lanes have CLI entry points but their pipelines don't yet
                                                    wrap themselves in <code>ComputeLog</code>. No runs reporting in
                                                    means no metrics shown — not a failure. Schedule + instrumentation
                                                    work is tracked in V1_COMPLETION_CHECKLIST §7.
                                                </p>
                                            </details>
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    @endif
                </div>

                {{-- Surface health --}}
                @if (count($surface_freshness) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">Surface health</h3>
                        <table style="width:100%; font-size:0.72rem; color:#e2e8f0; border-collapse:separate; border-spacing:0;">
                            <thead style="color:#cbd5e1; background:rgba(255,255,255,0.04);">
                                <tr>
                                    <th style="text-align:left; padding:6px 8px; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.15);">surface</th>
                                    <th style="text-align:left; padding:6px 8px; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.15);" title="Per-surface health badge derived from the age of the newest row vs the TTL ladder in config/intel_ttl.json">badge</th>
                                    <th style="text-align:right; padding:6px 8px; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.15);" title="Age of the newest row on this surface — answers 'is the latest data fresh enough to act on?'">newest age</th>
                                    <th style="text-align:right; padding:6px 8px; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.15);">total</th>
                                    <th style="text-align:right; padding:6px 8px; font-weight:600; color:#86efac; border-bottom:1px solid rgba(255,255,255,0.15);" title="Rows within the surface's fresh TTL window">fresh</th>
                                    <th style="text-align:right; padding:6px 8px; font-weight:600; color:#fde68a; border-bottom:1px solid rgba(255,255,255,0.15);" title="Rows within the surface's aging TTL window">aging</th>
                                    <th style="text-align:right; padding:6px 8px; font-weight:600; color:#fdba74; border-bottom:1px solid rgba(255,255,255,0.15);" title="Rows within the surface's stale TTL window">stale</th>
                                    <th style="text-align:right; padding:6px 8px; font-weight:600; color:#fb7185; border-bottom:1px solid rgba(255,255,255,0.15);" title="Rows past the surface's expired TTL window — historical / sealed records">expired</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($surface_freshness as $surface => $tally)
                                    @php
                                        $health = $surface_health[$surface] ?? 'healthy';
                                        $col = $surfaceStateColors[$health] ?? '#9ca3af';
                                        $ageH = $tally['newest_age_h'] ?? null;
                                        $ageDisplay = $ageH === null
                                            ? '—'
                                            : ($ageH < 1 ? '<1h' : ($ageH < 48 ? "{$ageH}h" : floor($ageH / 24) . 'd'));
                                        $stripe = $loop->iteration % 2 === 0 ? 'background:rgba(255,255,255,0.02);' : '';
                                    @endphp
                                    <tr style="border-top:1px solid rgba(255,255,255,0.08); {{ $stripe }}">
                                        <td style="padding:6px 8px;">{{ str_replace('_', ' ', $surface) }}</td>
                                        <td style="padding:6px 8px;"><span style="font-size:0.6rem; padding:2px 8px; border-radius:3px; background:rgba(255,255,255,0.06); color:{{ $col }}; font-weight:600;">{{ $health }}</span></td>
                                        <td style="padding:6px 8px; text-align:right; color:{{ $col }}; font-weight:600;">{{ $ageDisplay }}</td>
                                        <td style="padding:6px 8px; text-align:right;">{{ number_format($tally['total']) }}</td>
                                        <td style="padding:6px 8px; text-align:right; color:#86efac;">{{ $tally['fresh'] ?? 0 }}</td>
                                        <td style="padding:6px 8px; text-align:right; color:#fde68a;">{{ $tally['aging'] ?? 0 }}</td>
                                        <td style="padding:6px 8px; text-align:right; color:#fdba74;">{{ $tally['stale'] ?? 0 }}</td>
                                        <td style="padding:6px 8px; text-align:right; color:#fb7185;">{{ $tally['expired'] ?? 0 }}</td>
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
                                        @php
                                            $sCol = $statusColors[$r->status] ?? '#9ca3af';
                                            $stripe = $loop->iteration % 2 === 0 ? 'background:rgba(255,255,255,0.02);' : '';
                                        @endphp
                                        <tr style="border-top:1px solid rgba(255,255,255,0.08); {{ $stripe }}">
                                            <td style="padding:4px 6px; color:#cbd5e1;"><x-relative-time :ts="$r->compute_started_at" /></td>
                                            <td style="padding:4px 6px;">{{ str_replace('_', ' ', $r->lane) }}</td>
                                            <td style="padding:4px 6px;">{{ $r->pipeline }}</td>
                                            <td style="padding:4px 6px; color:{{ $sCol }}; font-weight:600;">{{ $r->status }}</td>
                                            <td style="padding:4px 6px; text-align:right;">{{ $r->compute_duration_ms ?? '—' }}</td>
                                            <td style="padding:4px 6px; text-align:right; color:#9ca3af;">{{ $r->source_row_count ?? '—' }}→{{ $r->generated_row_count ?? '—' }}</td>
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
                                    <div style="margin-top:0.3rem; font-size:0.6rem; color:#9ca3af; display:flex; gap:0.4rem;">
                                        <span><x-relative-time :ts="$e->detected_at" /></span>
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

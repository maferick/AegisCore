<x-filament-panels::page>
    @if (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">No bloc resolved.</p>
        </div>
    @else
        @php
            $tierColors = [
                'high' => '#86efac', 'strong' => '#7dd3fc',
                'adequate' => '#fde68a', 'low' => '#fb923c', 'untrusted' => '#fb7185',
            ];
            $statusColors = [
                'new' => '#9ca3af', 'acknowledged' => '#7dd3fc',
                'validated' => '#86efac', 'suppressed' => '#fde68a',
                'false_positive' => '#fca5a5', 'archived' => '#7a7a82',
            ];
        @endphp

        {{-- Verdict banner --}}
        <x-verdict-banner :verdict="$verdict ?? null" />

        <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
            <div style="font-size:0.72rem; color:#cbd5e1;">
                window: <strong style="color:#e5e5e7;">{{ $latest_end ?? '—' }}</strong> ·
                trust score formula:
                <code title="useful_rate = analyst-confirmed-useful / total feedback;
fp_rate = false-positive count / total feedback;
suppression_rate = analyst-suppressed / total fired alerts.
Zero-feedback baseline = 0.5 (no signal yet, neutral prior).">
                    0.6 × useful_rate + 0.3 × (1−fp_rate) + 0.1 × (1−suppression_rate)
                </code>
            </div>
        </div>

        {{-- Freshness rollup strip --}}
        @if (! empty($freshness))
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
                <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">Freshness across surfaces</h3>
                <table style="width:100%; font-size:0.7rem; color:#cbd5e1; border-collapse:collapse;">
                    <thead style="color:#7a7a82;">
                        <tr><th style="text-align:left;">surface</th><th style="text-align:right; color:#86efac;">fresh</th><th style="text-align:right; color:#fde68a;">aging</th><th style="text-align:right; color:#fdba74;">stale</th><th style="text-align:right; color:#fb7185;">expired</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($freshness as $surface => $tally)
                            @php
                                $total = array_sum($tally);
                                if ($total === 0) continue;
                            @endphp
                            <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                <td style="padding:3px 4px;">{{ str_replace('_', ' ', $surface) }}</td>
                                <td style="padding:3px 4px; text-align:right; color:#86efac;">{{ number_format($tally['fresh'] ?? 0) }}</td>
                                <td style="padding:3px 4px; text-align:right; color:#fde68a;">{{ number_format($tally['aging'] ?? 0) }}</td>
                                <td style="padding:3px 4px; text-align:right; color:#fdba74;">{{ number_format($tally['stale'] ?? 0) }}</td>
                                <td style="padding:3px 4px; text-align:right; color:#fb7185;">{{ number_format($tally['expired'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div style="margin-top:0.4rem; font-size:0.55rem; color:#7a7a82; font-style:italic;">TTL ladders defined per surface in <code>App\Services\IntelFreshness::SURFACE_TTL</code>. Pre-computed via <code>ci-phase49-freshness</code>; live re-evaluated on each render so aging happens between compute runs.</div>
            </div>
        @endif

        <div style="display:grid; grid-template-columns:minmax(0,1.4fr) minmax(0,1fr); gap:1rem;">
            <div style="display:grid; gap:0.75rem;">
                {{-- Surface trust scoreboard --}}
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">Surface trust</h3>
                    @if (count($rows) === 0)
                        <p style="font-size:0.75rem; color:#7a7a82; font-style:italic;">No trust metrics yet — run ci-phase48-trust-metrics.</p>
                    @else
                        <table style="width:100%; font-size:0.7rem; color:#cbd5e1; border-collapse:collapse;">
                            <thead style="color:#7a7a82;">
                                <tr><th style="text-align:left;">surface</th><th style="text-align:right;">items</th><th style="text-align:right;">useful</th><th style="text-align:right;">fp/misleading</th><th style="text-align:right;">suppressed</th><th style="text-align:right;">overrides</th><th style="text-align:right;">trust</th><th style="text-align:left;">tier</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    @php $col = $tierColors[$r->trust_tier] ?? '#9ca3af'; @endphp
                                    <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                        <td style="padding:3px 4px;"><strong>{{ str_replace('_', ' ', $r->surface) }}</strong></td>
                                        <td style="text-align:right; padding:3px 4px;">{{ number_format($r->total_items) }}</td>
                                        <td style="text-align:right; padding:3px 4px; color:#86efac;">{{ $r->useful_count }} <span style="color:#7a7a82;">({{ number_format(((float)$r->useful_rate)*100, 1) }}%)</span></td>
                                        <td style="text-align:right; padding:3px 4px; color:{{ $r->false_positive_count + $r->misleading_count > 0 ? '#fca5a5' : '#9ca3af' }};">
                                            {{ $r->false_positive_count + $r->misleading_count }} <span style="color:#7a7a82;">({{ number_format(((float)$r->false_positive_rate)*100, 1) }}%)</span>
                                        </td>
                                        <td style="text-align:right; padding:3px 4px;">{{ $r->suppression_count }} <span style="color:#7a7a82;">({{ number_format(((float)$r->suppression_rate)*100, 1) }}%)</span></td>
                                        <td style="text-align:right; padding:3px 4px;">{{ $r->analyst_override_count }}</td>
                                        <td style="text-align:right; padding:3px 4px; color:{{ $col }};"><strong>{{ number_format((float)$r->trust_score, 3) }}</strong></td>
                                        <td style="padding:3px 4px; color:{{ $col }};">{{ $r->trust_tier }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>

                {{-- Feedback histogram --}}
                @if (count($feedback) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">Feedback events · last 60d</h3>
                        @php
                            $byKind = [];
                            foreach ($feedback as $f) {
                                $byKind[$f->surface][$f->feedback_kind] = (int) $f->n;
                            }
                        @endphp
                        @foreach ($byKind as $surface => $kinds)
                            <div style="font-size:0.7rem; padding:0.3rem 0.4rem; background:rgba(255,255,255,0.02); border-radius:4px; margin-bottom:0.2rem;">
                                <strong style="color:#a5b4fc;">{{ $surface }}</strong>
                                @foreach ($kinds as $kind => $n)
                                    @php $col = match($kind) { 'useful', 'strategic' => '#86efac', 'misleading', 'incorrect_escalation', 'incorrect_doctrine', 'incorrect_linkage' => '#fca5a5', 'noisy', 'duplicate' => '#fde68a', default => '#9ca3af' }; @endphp
                                    · <span style="color:{{ $col }};">{{ str_replace('_', ' ', $kind) }}</span> <span style="color:#7a7a82;">{{ $n }}</span>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div style="display:grid; gap:0.75rem;">
                {{-- Alert lifecycle distribution --}}
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">Alert lifecycle</h3>
                    <div style="display:grid; gap:0.2rem; font-size:0.7rem;">
                        @foreach (['new', 'acknowledged', 'validated', 'suppressed', 'false_positive', 'archived'] as $status)
                            @php $col = $statusColors[$status] ?? '#9ca3af'; $n = $alert_summary[$status] ?? 0; @endphp
                            <div style="display:flex; gap:0.3rem; align-items:center; padding:0.2rem 0.4rem; background:rgba(255,255,255,0.02); border-radius:4px;">
                                <span style="color:{{ $col }};">{{ str_replace('_', ' ', $status) }}</span>
                                <span style="margin-left:auto; color:#cbd5e1;">{{ $n }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Verified items breakdown --}}
                @if (count($verified_summary) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">Verified intelligence corpus</h3>
                        <div style="display:grid; gap:0.2rem; font-size:0.7rem;">
                            @foreach ($verified_summary as $kind => $n)
                                <div style="display:flex; gap:0.3rem; align-items:center; padding:0.2rem 0.4rem; background:rgba(255,255,255,0.02); border-radius:4px;">
                                    <span style="color:#86efac;">{{ str_replace('_', ' ', $kind) }}</span>
                                    <span style="margin-left:auto; color:#cbd5e1;">{{ $n }}</span>
                                </div>
                            @endforeach
                        </div>
                        <div style="margin-top:0.4rem; text-align:right;"><a href="/portal/intelligence/verified" style="font-size:0.6rem; color:#7dd3fc; text-decoration:none;">manage →</a></div>
                    </div>
                @endif

                {{-- Active suppression rules --}}
                @if (count($suppression_rules) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">Active suppression rules ({{ count($suppression_rules) }})</h3>
                        <div style="display:grid; gap:0.2rem; font-size:0.65rem;">
                            @foreach ($suppression_rules as $r)
                                <div style="padding:0.2rem 0.4rem; background:rgba(255,255,255,0.02); border-radius:4px;">
                                    <span style="color:#fde68a;">{{ str_replace('_', ' ', $r->rule_kind) }}</span>
                                    @if ($r->target_alert_kind)
                                        <span style="color:#a5b4fc;"> · {{ $r->target_alert_kind }}</span>
                                    @endif
                                    @if ($r->reason)
                                        <div style="color:#cbd5e1; margin-top:0.1rem;">{{ $r->reason }}</div>
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

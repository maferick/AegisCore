<x-filament-panels::page>
    @if (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">No bloc resolved.</p>
        </div>
    @else
        @php
            $confColors = [
                'confirmed' => '#a5b4fc', 'high' => '#fb7185',
                'medium' => '#fdba74', 'low' => '#9ca3af',
            ];
            $sevColors = [
                'critical' => '#fb7185', 'elevated' => '#fdba74',
                'watch' => '#fde68a', 'info' => '#9ca3af',
            ];
            $domainColors = [
                'graph' => '#a5b4fc', 'operational' => '#fdba74',
                'temporal' => '#7dd3fc', 'community' => '#fde68a',
                'battle' => '#86efac',
            ];
            $freshColors = [
                'fresh' => '#86efac', 'aging' => '#fde68a',
                'stale' => '#fdba74', 'expired' => '#fb7185',
            ];
        @endphp

        <x-verdict-banner :verdict="$verdict ?? null" />

        <div style="display:flex; gap:0.5rem; align-items:center; margin-bottom:0.75rem; flex-wrap:wrap;">
            <span style="font-size:0.65rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.1em;">min confidence</span>
            @foreach ($available_bands as $b)
                <a href="?min_band={{ $b }}"
                   style="text-decoration:none; padding:4px 10px; font-size:0.78rem; border-radius:5px;
                          background:{{ $b === $min_band ? 'rgba(165,180,252,0.18)' : 'rgba(255,255,255,0.04)' }};
                          color:{{ $b === $min_band ? '#a5b4fc' : '#cbd5e1' }};
                          border:1px solid {{ $b === $min_band ? '#a5b4fc' : 'rgba(255,255,255,0.10)' }};">
                    {{ $b }}
                </a>
            @endforeach
            <span style="margin-left:auto; font-size:0.65rem; color:#7a7a82;">
                last fusion run: <x-relative-time :ts="$last_run" />
            </span>
        </div>

        <div class="fi-section rounded-xl"
             style="padding:0.5rem 0.75rem; margin-bottom:0.75rem;
                    background:rgba(165,180,252,0.06);
                    border:1px solid rgba(165,180,252,0.20);
                    font-size:0.7rem; color:#cbd5e1;">
            <strong>Hypotheses, not verdicts.</strong> Every card shows confidence, evidence,
            source rows, caveats, and why-strengthened. AI proposes; operator commits. Per
            <a href="/docs/adr/0013-hypothesis-confidence-framing.md" style="color:#a5b4fc;">ADR 0013</a>.
        </div>

        @if (count($cards) === 0)
            <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                 style="font-size:0.78rem; color:#cbd5e1;">
                No active hypotheses at <em>{{ $min_band }}</em> confidence or above. The
                fusion pipeline runs on cron; if the queue stays empty over multiple cycles
                that's the system reporting "no persistent multi-domain suspicious patterns
                detected" — which is itself the desired operational state.
            </div>
        @else
            <div style="display:grid; gap:0.6rem;">
                @foreach ($cards as $c)
                    @php
                        $cCol = $confColors[$c['confidence']] ?? '#9ca3af';
                        $sCol = $sevColors[$c['severity']]   ?? '#9ca3af';
                        $fCol = $freshColors[$c['freshness_state']] ?? '#9ca3af';
                    @endphp
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                         style="border-left:4px solid {{ $cCol }};">
                        <div style="display:flex; gap:0.5rem; align-items:baseline; flex-wrap:wrap;">
                            <span style="font-size:0.55rem; padding:2px 8px; border-radius:3px; background:rgba(255,255,255,0.04); color:{{ $cCol }}; font-weight:600; text-transform:uppercase; letter-spacing:0.08em;">
                                conf {{ $c['confidence'] }}
                            </span>
                            <span style="font-size:0.55rem; padding:2px 8px; border-radius:3px; background:rgba(255,255,255,0.04); color:{{ $sCol }}; font-weight:600; text-transform:uppercase; letter-spacing:0.08em;">
                                sev {{ $c['severity'] }}
                            </span>
                            <span style="font-size:0.55rem; padding:2px 8px; border-radius:3px; background:rgba(255,255,255,0.04); color:{{ $fCol }};">
                                {{ $c['freshness_state'] }}
                            </span>
                            <span style="font-size:0.55rem; color:#7a7a82;">score {{ number_format($c['score'], 2) }} · {{ $c['corroboration'] }}-domain</span>
                            <span style="margin-left:auto; font-size:0.6rem; color:#9ca3af;">strengthened <x-relative-time :ts="$c['last_strengthened_at']" /></span>
                        </div>

                        <h3 style="font-size:1rem; color:#e2e8f0; margin:0.4rem 0 0.2rem;">
                            <a href="/portal/intelligence/character-lookup?cid={{ $c['character_id'] }}" style="color:inherit; text-decoration:none; border-bottom:1px dotted rgba(255,255,255,0.15);">{{ $c['character_name'] }}</a>
                        </h3>
                        <p style="font-size:0.78rem; color:#cbd5e1; margin:0 0 0.4rem; line-height:1.5;">
                            {{ $c['summary'] }}
                        </p>

                        @if (count($c['signals']) > 0)
                            <div style="display:flex; gap:0.3rem; flex-wrap:wrap; margin:0.3rem 0;">
                                @foreach ($c['signals'] as $s)
                                    @php $dCol = $domainColors[$s['domain'] ?? ''] ?? '#9ca3af'; @endphp
                                    <span style="font-size:0.55rem; padding:2px 8px; border-radius:3px; background:rgba(255,255,255,0.04); color:{{ $dCol }};"
                                          title="{{ $s['evidence'] ?? $s['kind'] }}">
                                        {{ str_replace('_', ' ', $s['kind'] ?? '?') }}
                                        @if (isset($s['domain']))
                                            <span style="opacity:0.7;">· {{ $s['domain'] }}</span>
                                        @endif
                                    </span>
                                @endforeach
                            </div>
                            <details style="margin-top:0.3rem;">
                                <summary style="font-size:0.65rem; color:#7dd3fc; cursor:pointer;">evidence ({{ count($c['signals']) }} signals)</summary>
                                <ul style="margin:0.3rem 0 0.3rem 1.2rem; font-size:0.7rem; color:#cbd5e1; line-height:1.55;">
                                    @foreach ($c['signals'] as $s)
                                        <li>{{ $s['evidence'] ?? '?' }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif

                        @if (count($c['caveats']) > 0)
                            <details style="margin-top:0.2rem;">
                                <summary style="font-size:0.65rem; color:#fdba74; cursor:pointer;">caveats ({{ count($c['caveats']) }})</summary>
                                <ul style="margin:0.3rem 0 0.3rem 1.2rem; font-size:0.7rem; color:#cbd5e1; line-height:1.55;">
                                    @foreach ($c['caveats'] as $cv)
                                        <li>{{ $cv }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif

                        @if (! empty($c['why_strengthened']))
                            <details style="margin-top:0.2rem;">
                                <summary style="font-size:0.65rem; color:#86efac; cursor:pointer;">why-strengthened</summary>
                                <pre style="margin:0.3rem 0 0.3rem 0; font-size:0.65rem; color:#cbd5e1; white-space:pre-wrap; background:rgba(0,0,0,0.18); padding:0.4rem; border-radius:4px;">{{ json_encode($c['why_strengthened'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        @endif

                        @if (count($c['source_refs']) > 0)
                            <details style="margin-top:0.2rem;">
                                <summary style="font-size:0.65rem; color:#a5b4fc; cursor:pointer;">source rows ({{ count($c['source_refs']) }})</summary>
                                <ul style="margin:0.3rem 0 0.3rem 1.2rem; font-size:0.7rem; color:#cbd5e1; line-height:1.55;">
                                    @foreach ($c['source_refs'] as $ref)
                                        <li>
                                            <code>{{ $ref['table'] ?? '?' }}.{{ $ref['field'] ?? '?' }}</code>
                                            @if (! empty($ref['url']))
                                                · <a href="{{ $ref['url'] }}" style="color:#7dd3fc;">open →</a>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif

                        <div style="margin-top:0.4rem; font-size:0.55rem; color:#7a7a82;">
                            first seen <x-relative-time :ts="$c['first_seen_at']" /> ·
                            recomputed <x-relative-time :ts="$c['last_recomputed_at']" /> ·
                            model <code>{{ $c['ai_model'] ?? 'rule_based_v1' }}</code>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</x-filament-panels::page>

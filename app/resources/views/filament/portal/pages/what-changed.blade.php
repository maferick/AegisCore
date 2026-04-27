<x-filament-panels::page>
    @if (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">No bloc resolved.</p>
        </div>
    @else
        @php
            $sevColors = ['critical' => '#fb7185', 'elevated' => '#fdba74',
                          'warning' => '#fde68a', 'info' => '#9ca3af'];
            $confColors = ['confirmed' => '#a5b4fc', 'high' => '#86efac',
                           'medium' => '#fde68a', 'low' => '#9ca3af'];
            $freshColors = ['fresh' => '#86efac', 'aging' => '#fde68a',
                            'stale' => '#fdba74', 'expired' => '#fb7185'];
        @endphp

        {{-- Window selector --}}
        <div style="display:flex; gap:0.4rem; align-items:center; margin-bottom:0.75rem; flex-wrap:wrap;">
            <span style="font-size:0.6rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.1em;">window</span>
            @foreach ($available_windows as $w)
                <a href="?window={{ $w }}"
                   class="fi-section rounded-md"
                   style="padding:4px 10px; font-size:0.75rem; text-decoration:none;
                          background:{{ $w === $window ? 'rgba(134,239,172,0.18)' : 'rgba(255,255,255,0.04)' }};
                          color:{{ $w === $window ? '#86efac' : '#cbd5e1' }};
                          border:1px solid {{ $w === $window ? '#86efac' : 'rgba(255,255,255,0.08)' }};">
                    {{ $w }}
                </a>
            @endforeach
            <span style="font-size:0.6rem; color:#7a7a82; margin-left:auto;">
                latest pipeline run: <code>{{ $latest_generated_at ?? '—' }}</code>
            </span>
        </div>

        {{-- Disclaimer ribbon — ADR 0013 hypothesis framing --}}
        <div class="fi-section rounded-xl"
             style="padding:0.5rem 0.75rem; margin-bottom:0.75rem;
                    background:rgba(165,180,252,0.08);
                    border:1px solid rgba(165,180,252,0.25);
                    font-size:0.7rem; color:#cbd5e1;">
            These are <strong>operational hypotheses</strong>, not verdicts. Every card
            ships with confidence, evidence, source references, caveats, freshness, and
            why-strengthened. AI proposes; you commit. Per
            <a href="/docs/adr/0013-hypothesis-confidence-framing.md" style="color:#a5b4fc;">ADR 0013</a>.
        </div>

        @if (count($cards) === 0)
            <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p style="font-size:0.85rem; color:#7a7a82;">
                    No findings for the {{ $window }} window yet. The pipeline runs on cron;
                    re-check after the next cycle. Manual run:
                    <code>VIEWER_BLOC=&lt;id&gt; WINDOW={{ $window }} make ci-phase17-what-changed</code>
                </p>
            </div>
        @else
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(380px, 1fr)); gap:0.75rem;">
                @foreach ($cards as $c)
                    @php
                        $sev = $sevColors[$c['severity']] ?? '#9ca3af';
                        $con = $confColors[$c['confidence']] ?? '#9ca3af';
                        $fresh = $freshColors[$c['freshness_state']] ?? '#9ca3af';
                    @endphp
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center; margin-bottom:0.4rem;">
                            <span style="font-size:0.55rem; padding:2px 8px; border-radius:3px; background:rgba(255,255,255,0.04); color:{{ $sev }}; text-transform:uppercase; letter-spacing:0.08em;">
                                {{ $c['severity'] }}
                            </span>
                            <span style="font-size:0.55rem; padding:2px 8px; border-radius:3px; background:rgba(255,255,255,0.04); color:{{ $con }}; text-transform:uppercase; letter-spacing:0.08em;">
                                conf: {{ $c['confidence'] }}
                            </span>
                            <span style="font-size:0.55rem; padding:2px 8px; border-radius:3px; background:rgba(255,255,255,0.04); color:{{ $fresh }};">
                                {{ $c['freshness_state'] }}
                            </span>
                            <span style="font-size:0.55rem; color:#7a7a82; margin-left:auto;">
                                {{ $c['summary_type'] }}
                            </span>
                        </div>
                        <h3 style="font-size:0.95rem; color:#e2e8f0; margin:0 0 0.3rem; line-height:1.25;">
                            {{ $c['title'] }}
                        </h3>
                        <p style="font-size:0.78rem; color:#cbd5e1; margin:0 0 0.5rem; line-height:1.5;">
                            {{ $c['summary'] }}
                        </p>

                        {{-- Caveats — ADR 0013 binding field --}}
                        @if (count($c['caveats']) > 0)
                            <details style="margin-top:0.4rem;">
                                <summary style="font-size:0.65rem; color:#fdba74; cursor:pointer;">caveats ({{ count($c['caveats']) }})</summary>
                                <ul style="margin:0.3rem 0 0.3rem 1rem; font-size:0.7rem; color:#cbd5e1;">
                                    @foreach ($c['caveats'] as $cv)
                                        <li>{{ $cv }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif

                        {{-- Why strengthened — ADR 0013 binding field --}}
                        @if (! empty($c['why_strengthened']))
                            <details style="margin-top:0.3rem;">
                                <summary style="font-size:0.65rem; color:#86efac; cursor:pointer;">why-strengthened</summary>
                                <pre style="margin:0.3rem 0 0.3rem 0; font-size:0.65rem; color:#cbd5e1; white-space:pre-wrap; background:rgba(0,0,0,0.18); padding:0.4rem; border-radius:4px;">{{ json_encode($c['why_strengthened'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        @endif

                        {{-- Source references — ADR 0013 binding field --}}
                        @if (count($c['source_refs']) > 0)
                            <details style="margin-top:0.3rem;">
                                <summary style="font-size:0.65rem; color:#7dd3fc; cursor:pointer;">source references ({{ count($c['source_refs']) }})</summary>
                                <ul style="margin:0.3rem 0 0.3rem 1rem; font-size:0.7rem; color:#cbd5e1;">
                                    @foreach ($c['source_refs'] as $ref)
                                        <li>
                                            <code>{{ $ref['table'] ?? '?' }}.{{ $ref['field'] ?? '?' }}</code>
                                            @if (! empty($ref['url']))
                                                · <a href="{{ $ref['url'] }}" style="color:#7dd3fc;">open</a>
                                            @endif
                                            @if (! empty($ref['where']))
                                                <br><code style="font-size:0.6rem; color:#7a7a82;">{{ $ref['where'] }}</code>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif

                        {{-- Evidence — full delta payload --}}
                        @if (! empty($c['evidence']))
                            <details style="margin-top:0.3rem;">
                                <summary style="font-size:0.65rem; color:#a5b4fc; cursor:pointer;">evidence</summary>
                                <pre style="margin:0.3rem 0 0.3rem 0; font-size:0.65rem; color:#cbd5e1; white-space:pre-wrap; background:rgba(0,0,0,0.18); padding:0.4rem; border-radius:4px; max-height:200px; overflow:auto;">{{ json_encode($c['evidence'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        @endif

                        <div style="margin-top:0.5rem; font-size:0.55rem; color:#7a7a82;">
                            current: {{ $c['current_window_start'] }} → {{ $c['current_window_end'] }}<br>
                            comparison: {{ $c['comparison_window_start'] }} → {{ $c['comparison_window_end'] }}<br>
                            generated: {{ $c['generated_at'] }} · model: <code>{{ $c['ai_model'] ?? 'rule_based_v1' }}</code>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</x-filament-panels::page>

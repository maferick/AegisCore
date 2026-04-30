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

        <details style="margin-bottom:0.75rem; font-size:0.7rem; color:#9ca3af;">
            <summary style="cursor:pointer;">about these hypotheses + related surfaces</summary>
            <p style="margin:0.4rem 0; line-height:1.5;">
                AI-generated <strong>hypotheses</strong>, not verdicts. Each card shows
                confidence, evidence, source rows, caveats, and why-strengthened.
                AI proposes; you commit. Per
                <a href="/docs/adr/0013-hypothesis-confidence-framing.md" style="color:#a5b4fc;">ADR 0013</a>.
            </p>
            <p style="margin:0.4rem 0; line-height:1.5;">
                Related surfaces:
                <a href="/portal/counter-intel" style="color:#a5b4fc;">Counter-Intel Overview</a>
                (signal distribution + recent escalations + friendly-pilot red-contact panel) ·
                <a href="/portal/counter-intel/watchlist" style="color:#a5b4fc;">Watchlist</a>
                (operator-managed review queue) ·
                <a href="/portal/intelligence/verified" style="color:#a5b4fc;">Verified intelligence</a>
                (pinned findings).
            </p>
        </details>

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
                            <a href="/portal/characters/lookup?cid={{ $c['character_id'] }}" style="color:inherit; text-decoration:none; border-bottom:1px dotted rgba(255,255,255,0.15);">{{ $c['character_name'] }}</a>
                            @if (! empty($c['cluster_hint']))
                                <span style="margin-left:0.5rem; font-size:0.6rem; padding:2px 8px; border-radius:3px; background:rgba(165,180,252,0.12); color:#a5b4fc; font-weight:500; text-transform:uppercase; letter-spacing:0.06em;"
                                      title="Shares the prefix '{{ $c['cluster_hint']['prefix'] }}' with {{ $c['cluster_hint']['sibling_count'] }} other suspicious pilot{{ $c['cluster_hint']['sibling_count'] === 1 ? '' : 's' }}. Possible alt-pattern — investigate together.">
                                    + {{ $c['cluster_hint']['sibling_count'] }} alt-hint
                                </span>
                            @endif
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

                        @if (! empty($c['ai_status']))
                            @php
                                $ai = $c['ai_status'];
                                $aiOut = $ai['ai_output'] ?? [];
                            @endphp
                            <div style="display:flex; gap:0.35rem; align-items:center; margin-top:0.4rem; flex-wrap:wrap; font-size:0.55rem; color:#9ca3af;">
                                <span style="padding:2px 8px; border-radius:3px; background:rgba(165,180,252,0.10); color:#a5b4fc; text-transform:uppercase; letter-spacing:0.06em;"
                                      title="Tier used for the most recent synthesis. fast = stepfun-ai/step-3.5-flash. heavy = mistral-large-3.">
                                    AI {{ $ai['tier'] }}
                                </span>
                                <span title="Model that produced the active summary">
                                    <code>{{ $ai['model_used'] ?? '?' }}</code>
                                </span>
                                <span title="evidence rows kept after no-hallucinate validator">
                                    · {{ $ai['evidence_count'] }} evidence
                                </span>
                                @if ($ai['hallucination_drops'] > 0)
                                    <span style="color:#fb7185;" title="Evidence rows dropped because the AI cited a source_table not present in the input prompt">
                                        · {{ $ai['hallucination_drops'] }} drop{{ $ai['hallucination_drops'] === 1 ? '' : 's' }}
                                    </span>
                                @endif
                                @if ($ai['fell_back'])
                                    <span style="color:#fdba74;" title="Primary model failed and the JSON-safety-net fallback model produced this summary">
                                        · fellback
                                    </span>
                                @endif
                                <span title="Latency of the synthesis call">· {{ $ai['latency_ms'] }} ms</span>
                                <span style="color:#7a7a82;">· generated <x-relative-time :ts="$ai['generated_at']" /></span>
                            </div>

                            @if (! empty($aiOut))
                                <details style="margin-top:0.4rem;" open>
                                    <summary style="font-size:0.65rem; color:#a5b4fc; cursor:pointer; font-weight:600;">
                                        AI synthesis ({{ $ai['tier'] }} tier)
                                    </summary>
                                    <div style="margin:0.4rem 0 0 0.2rem; padding:0.5rem 0.7rem; background:rgba(165,180,252,0.04); border-left:2px solid rgba(165,180,252,0.30); border-radius:4px;">
                                        @if (! empty($aiOut['summary']))
                                            <p style="font-size:0.78rem; color:#e2e8f0; margin:0 0 0.4rem; line-height:1.55;">
                                                {{ $aiOut['summary'] }}
                                            </p>
                                        @endif
                                        @if (! empty($aiOut['confidence_reasoning']))
                                            <p style="font-size:0.7rem; color:#cbd5e1; margin:0 0 0.4rem; line-height:1.55;">
                                                <strong style="color:#a5b4fc;">Confidence reasoning:</strong>
                                                {{ $aiOut['confidence_reasoning'] }}
                                            </p>
                                        @endif
                                        @if (is_array($aiOut['key_evidence'] ?? null) && count($aiOut['key_evidence']) > 0)
                                            <div style="margin:0.3rem 0;">
                                                <div style="font-size:0.62rem; color:#7dd3fc; font-weight:600; margin-bottom:0.2rem;">key evidence ({{ count($aiOut['key_evidence']) }})</div>
                                                <ul style="margin:0 0 0 1.2rem; font-size:0.72rem; color:#cbd5e1; line-height:1.55;">
                                                    @foreach ($aiOut['key_evidence'] as $ev)
                                                        <li>
                                                            {{ $ev['claim'] ?? '?' }}
                                                            @if (! empty($ev['source_link']))
                                                                <a href="{{ $ev['source_link'] }}" style="color:#7dd3fc; text-decoration:none; border-bottom:1px dotted rgba(125,211,252,0.4);" title="{{ $ev['source_table'] ?? '' }}">
                                                                    [{{ $ev['source_table'] ?? '?' }}]
                                                                </a>
                                                            @elseif (! empty($ev['source_table']))
                                                                <code style="font-size:0.62rem; color:#9ca3af;">[{{ $ev['source_table'] }}]</code>
                                                            @endif
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                        @if (is_array($aiOut['caveats'] ?? null) && count($aiOut['caveats']) > 0)
                                            <div style="margin:0.3rem 0;">
                                                <div style="font-size:0.62rem; color:#fdba74; font-weight:600; margin-bottom:0.2rem;">caveats ({{ count($aiOut['caveats']) }})</div>
                                                <ul style="margin:0 0 0 1.2rem; font-size:0.72rem; color:#cbd5e1; line-height:1.55;">
                                                    @foreach ($aiOut['caveats'] as $cv)
                                                        <li>{{ is_string($cv) ? $cv : json_encode($cv) }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                        @if (is_array($aiOut['next_investigation_steps'] ?? null) && count($aiOut['next_investigation_steps']) > 0)
                                            <div style="margin:0.3rem 0;">
                                                <div style="font-size:0.62rem; color:#86efac; font-weight:600; margin-bottom:0.2rem;">next investigation steps</div>
                                                <ul style="margin:0 0 0 1.2rem; font-size:0.72rem; color:#cbd5e1; line-height:1.55;">
                                                    @foreach ($aiOut['next_investigation_steps'] as $step)
                                                        @if (is_array($step))
                                                            <li>
                                                                {{ $step['query'] ?? json_encode($step) }}
                                                                @if (! empty($step['rationale']))
                                                                    <span style="color:#7a7a82;"> — {{ $step['rationale'] }}</span>
                                                                @endif
                                                            </li>
                                                        @else
                                                            <li>{{ $step }}</li>
                                                        @endif
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                    </div>
                                </details>
                            @endif
                        @endif

                        <div style="display:flex; gap:0.4rem; align-items:center; margin-top:0.5rem; flex-wrap:wrap;">
                            <a href="/portal/characters/lookup?cid={{ $c['character_id'] }}"
                               style="text-decoration:none; padding:5px 12px; background:rgba(125,211,252,0.12); color:#7dd3fc; border:1px solid rgba(125,211,252,0.25); border-radius:5px; font-size:0.72rem; font-weight:600;">
                                Investigate →
                            </a>
                            <a href="/portal/counter-intel/watchlist"
                               style="text-decoration:none; padding:5px 12px; background:rgba(255,255,255,0.04); color:#cbd5e1; border:1px solid rgba(255,255,255,0.10); border-radius:5px; font-size:0.72rem;">
                                Add to watchlist
                            </a>
                            <button type="button"
                                    wire:click="refineHeavy({{ $c['id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="refineHeavy({{ $c['id'] }})"
                                    style="cursor:pointer; padding:5px 12px; background:rgba(168,85,247,0.10); color:#c4b5fd; border:1px solid rgba(168,85,247,0.30); border-radius:5px; font-size:0.72rem;"
                                    title="Queues a heavy-tier (mistral-large-3) refinement. Single row per click, ~30–180s. ADR 0013 — band can lower or hold, never raise.">
                                <span wire:loading.remove wire:target="refineHeavy({{ $c['id'] }})">Refine with heavy model</span>
                                <span wire:loading wire:target="refineHeavy({{ $c['id'] }})">Queueing…</span>
                            </button>
                            <span style="margin-left:auto; font-size:0.55rem; color:#7a7a82;">
                                first seen <x-relative-time :ts="$c['first_seen_at']" /> ·
                                model <code>{{ $c['ai_model'] ?? 'rule_based_v1' }}</code>
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</x-filament-panels::page>

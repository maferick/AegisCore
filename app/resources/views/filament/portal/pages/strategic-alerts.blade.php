<x-filament-panels::page>
    @if (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">No bloc resolved.</p>
        </div>
    @else
        @php
            $sevColors = ['urgent' => '#fb7185', 'elevated' => '#fdba74', 'watch' => '#fde68a', 'info' => '#9ca3af'];
            $kindLabels = [
                'sudden_doctrine_shift' => 'doctrine shift',
                'capital_escalation' => 'capital escalation',
                'hostile_deployment_migration' => 'deployment migration',
                'escalation_into_staging' => 'escalation into staging',
                'corridor_pressure_spike' => 'corridor pressure',
                'operational_tempo_spike' => 'tempo spike',
                'large_strategic_cluster' => 'large cluster',
                'unusual_force_composition' => 'unusual composition',
            ];
        @endphp

        {{-- Filters --}}
        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
            <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; font-size:0.7rem;">
                <span style="color:#7a7a82;">status:</span>
                @foreach (['open', 'validated', 'suppressed', 'dismissed', 'all'] as $s)
                    @php
                        $active = $s === $status;
                        $count = match($s) { 'open' => $totals['open'] ?? 0, 'dismissed' => $totals['dismissed'] ?? 0, default => null };
                    @endphp
                    <a href="?status={{ $s }}@if($kind)&kind={{ $kind }}@endif"
                       style="padding:3px 8px; border-radius:4px; text-decoration:none;
                              background:{{ $active ? 'rgba(125,211,252,0.15)' : 'rgba(255,255,255,0.04)' }};
                              color:{{ $active ? '#7dd3fc' : '#9ca3af' }};">{{ $s }} @if($count !== null)<span style="opacity:0.6;">({{ $count }})</span>@endif</a>
                @endforeach

                <span style="margin-left:0.6rem; color:#7a7a82;">kind:</span>
                <a href="?status={{ $status }}" style="padding:3px 8px; border-radius:4px; text-decoration:none; background:{{ $kind === '' ? 'rgba(125,211,252,0.15)' : 'rgba(255,255,255,0.04)' }}; color:{{ $kind === '' ? '#7dd3fc' : '#9ca3af' }};">all</a>
                @foreach ($kindLabels as $k => $label)
                    @php $active = $k === $kind; $cnt = $kind_counts[$k] ?? 0; @endphp
                    <a href="?status={{ $status }}&kind={{ $k }}" style="padding:3px 8px; border-radius:4px; text-decoration:none; background:{{ $active ? 'rgba(125,211,252,0.15)' : 'rgba(255,255,255,0.04)' }}; color:{{ $active ? '#7dd3fc' : '#9ca3af' }};">{{ $label }} <span style="opacity:0.6;">({{ $cnt }})</span></a>
                @endforeach
            </div>
        </div>

        @if (count($alerts) === 0)
            <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p style="font-size:0.8rem; color:#9ca3af;">No alerts in this view.</p>
            </div>
        @else
            <div style="display:grid; gap:0.4rem;">
                @foreach ($alerts as $a)
                    @php
                        $col = $sevColors[$a->severity] ?? '#9ca3af';
                        $isDismissed = $a->dismissed_at !== null;
                        $statusColors = [
                            'new' => '#9ca3af', 'acknowledged' => '#7dd3fc',
                            'validated' => '#86efac', 'suppressed' => '#fde68a',
                            'false_positive' => '#fca5a5', 'archived' => '#7a7a82',
                        ];
                        $statusCol = $statusColors[$a->analyst_status] ?? '#9ca3af';
                        $opacity = ($a->analyst_status === 'archived' || $a->analyst_status === 'suppressed') ? '0.55' : '1';
                    @endphp
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                         style="border-left:4px solid {{ $col }}; opacity:{{ $opacity }};">
                        {{-- Metadata chip row — passive descriptors only, no actions --}}
                        <div style="display:flex; gap:0.4rem; align-items:center; flex-wrap:wrap; font-size:0.6rem;">
                            <span style="color:{{ $col }}; text-transform:uppercase; letter-spacing:0.08em; padding:2px 8px; border-radius:3px; background:rgba(255,255,255,0.04); font-weight:600;">{{ $a->severity }}</span>
                            <span style="color:#a5b4fc; text-transform:uppercase; letter-spacing:0.08em; padding:2px 8px; border-radius:3px; background:rgba(255,255,255,0.04);">{{ $kindLabels[$a->alert_kind] ?? $a->alert_kind }}</span>
                            <span style="color:{{ $statusCol }}; padding:2px 8px; border-radius:3px; background:rgba(255,255,255,0.04); text-transform:uppercase;">{{ str_replace('_', ' ', $a->analyst_status) }}</span>
                            <x-intel-freshness surface="alert"
                                :timestamp="$a->detected_at"
                                :persisted="$a->freshness_state ?? null"
                                :windowStart="$a->source_window_start ?? null"
                                :windowEnd="$a->source_window_end ?? null" />
                            <span style="color:#9ca3af; margin-left:auto;"><x-relative-time :ts="$a->detected_at" /></span>
                        </div>

                        {{-- Title row --}}
                        <h3 style="font-size:0.95rem; color:#e2e8f0; margin:0.4rem 0 0.2rem; line-height:1.35;">
                            {{ $a->title }}
                        </h3>
                        @if ($a->summary)
                            <p style="font-size:0.78rem; color:#cbd5e1; margin:0; line-height:1.5;">{{ $a->summary }}</p>
                        @endif

                        {{-- Action row — visually separated, bigger tap targets,
                             clearly distinct from the metadata chips above. --}}
                        <div style="display:flex; gap:0.4rem; margin-top:0.7rem; padding-top:0.5rem;
                                    border-top:1px solid rgba(255,255,255,0.06); flex-wrap:wrap; align-items:center;">
                            <span style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em; margin-right:0.3rem;">actions</span>
                            @if ($a->analyst_status !== 'validated')
                                <button wire:click="setStatus({{ $a->id }}, 'validated')"
                                        style="font-size:0.7rem; font-weight:600; padding:5px 12px; border-radius:5px;
                                               background:rgba(134,239,172,0.14); color:#86efac;
                                               border:1px solid rgba(134,239,172,0.30); cursor:pointer;">
                                    Validate
                                </button>
                            @endif
                            @if (in_array($a->analyst_status, ['new', 'suppressed']))
                                <button wire:click="setStatus({{ $a->id }}, 'acknowledged')"
                                        style="font-size:0.7rem; font-weight:600; padding:5px 12px; border-radius:5px;
                                               background:rgba(125,211,252,0.14); color:#7dd3fc;
                                               border:1px solid rgba(125,211,252,0.30); cursor:pointer;">
                                    Acknowledge
                                </button>
                            @endif
                            @if ($a->analyst_status !== 'suppressed')
                                <button wire:click="setStatus({{ $a->id }}, 'suppressed')"
                                        style="font-size:0.7rem; font-weight:500; padding:5px 12px; border-radius:5px;
                                               background:rgba(253,230,138,0.10); color:#fde68a;
                                               border:1px solid rgba(253,230,138,0.25); cursor:pointer;">
                                    Suppress 7d
                                </button>
                            @endif
                            @if ($a->analyst_status !== 'false_positive')
                                <button wire:click="setStatus({{ $a->id }}, 'false_positive')"
                                        style="font-size:0.7rem; font-weight:500; padding:5px 12px; border-radius:5px;
                                               background:rgba(252,165,165,0.10); color:#fca5a5;
                                               border:1px solid rgba(252,165,165,0.25); cursor:pointer;">
                                    False positive
                                </button>
                            @endif
                            @if (! $isDismissed)
                                <button wire:click="dismiss({{ $a->id }})"
                                        style="font-size:0.7rem; font-weight:500; padding:5px 12px; border-radius:5px;
                                               background:rgba(255,255,255,0.03); color:#9ca3af;
                                               border:1px solid rgba(255,255,255,0.10); cursor:pointer;">
                                    Archive
                                </button>
                            @endif
                        </div>

                        {{-- Suppression metadata row --}}
                        @if ($a->suppression_reason || $a->reviewed_by_user_id)
                            <div style="margin-top:0.3rem; font-size:0.55rem; color:#7a7a82;">
                                @if ($a->suppression_reason)
                                    <span>suppressed: {{ $a->suppression_reason }}</span>
                                    @if ($a->suppressed_until)
                                        <span> · until {{ $a->suppressed_until }}</span>
                                    @endif
                                @endif
                                @if ($a->reviewed_at)
                                    <span style="margin-left:0.5rem;">reviewed: {{ $a->reviewed_at }}</span>
                                @endif
                            </div>
                        @endif

                        {{-- Analyst notes (inline editor) --}}
                        <details style="margin-top:0.3rem;">
                            <summary style="font-size:0.55rem; color:#7dd3fc; cursor:pointer;">analyst notes{{ $a->analyst_notes ? ' (set)' : '' }}</summary>
                            <form onsubmit="event.preventDefault(); $wire.saveNotes({{ $a->id }}, this.elements['notes'].value)" style="margin-top:0.3rem;">
                                <textarea name="notes" rows="2" style="width:100%; padding:0.3rem 0.5rem; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.10); border-radius:4px; color:#e5e5e7; font-size:0.7rem;">{{ $a->analyst_notes }}</textarea>
                                <button type="submit" style="margin-top:0.2rem; font-size:0.55rem; padding:2px 6px; border-radius:3px; background:rgba(125,211,252,0.10); color:#7dd3fc; border:none; cursor:pointer;">save notes</button>
                            </form>
                        </details>

                        <div style="display:flex; gap:0.5rem; margin-top:0.3rem; font-size:0.6rem; color:#7a7a82;">
                            @if ($a->primary_system_name)
                                <span>system: <a href="/portal/operations/heatmap?system={{ $a->primary_system_name }}" style="color:#86efac; text-decoration:none;">{{ $a->primary_system_name }}</a></span>
                            @endif
                            @if ($a->primary_alliance_name)
                                <span>alliance: {{ $a->primary_alliance_name }}</span>
                            @endif
                            @if ($a->related_incident_id)
                                <span><a href="/portal/operations/incidents/{{ $a->related_incident_id }}" style="color:#c4b5fd; text-decoration:none;">incident #{{ $a->related_incident_id }} →</a></span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</x-filament-panels::page>

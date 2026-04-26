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
                @foreach (['open', 'all', 'dismissed'] as $s)
                    @php $active = $s === $status; $count = $totals[$s === 'all' ? 'open' : $s] ?? 0; @endphp
                    <a href="?status={{ $s }}@if($kind)&kind={{ $kind }}@endif"
                       style="padding:3px 8px; border-radius:4px; text-decoration:none;
                              background:{{ $active ? 'rgba(125,211,252,0.15)' : 'rgba(255,255,255,0.04)' }};
                              color:{{ $active ? '#7dd3fc' : '#9ca3af' }};">{{ $s }} <span style="opacity:0.6;">{{ $s === 'all' ? '' : '('.$count.')' }}</span></a>
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
                        $isAcked = $a->acknowledged_at !== null;
                        $isDismissed = $a->dismissed_at !== null;
                        $opacity = $isDismissed ? '0.5' : '1';
                    @endphp
                    <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                         style="border-left:4px solid {{ $col }}; opacity:{{ $opacity }};">
                        <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                            <span style="font-size:0.55rem; color:{{ $col }}; text-transform:uppercase; letter-spacing:0.08em; padding:1px 6px; border-radius:3px; background:rgba(255,255,255,0.04);">{{ $a->severity }}</span>
                            <span style="font-size:0.55rem; color:#a5b4fc; text-transform:uppercase; letter-spacing:0.08em;">{{ $kindLabels[$a->alert_kind] ?? $a->alert_kind }}</span>
                            <span style="font-size:0.85rem; color:#e5e5e7; flex:1;">{{ $a->title }}</span>
                            <span style="font-size:0.6rem; color:#7a7a82;">{{ $a->detected_at }}</span>
                            <div style="display:flex; gap:0.25rem;">
                                @if (! $isAcked && ! $isDismissed)
                                    <button wire:click="ack({{ $a->id }})" style="font-size:0.55rem; padding:2px 6px; border-radius:3px; background:rgba(125,211,252,0.10); color:#7dd3fc; border:none; cursor:pointer;">ack</button>
                                @elseif ($isAcked && ! $isDismissed)
                                    <span style="font-size:0.55rem; padding:2px 6px; border-radius:3px; background:rgba(134,239,172,0.10); color:#86efac;">acked</span>
                                @endif
                                @if (! $isDismissed)
                                    <button wire:click="dismiss({{ $a->id }})" style="font-size:0.55rem; padding:2px 6px; border-radius:3px; background:rgba(252,165,165,0.10); color:#fca5a5; border:none; cursor:pointer;">dismiss</button>
                                @else
                                    <span style="font-size:0.55rem; padding:2px 6px; border-radius:3px; background:rgba(255,255,255,0.04); color:#7a7a82;">dismissed</span>
                                @endif
                            </div>
                        </div>
                        @if ($a->summary)
                            <div style="font-size:0.7rem; color:#cbd5e1; margin-top:0.3rem;">{{ $a->summary }}</div>
                        @endif
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

<x-filament-panels::page>
    @if (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">No bloc resolved.</p>
        </div>
    @else
        @php
            $tierColors = [
                'strategic' => '#d8b4fe',
                'hot' => '#fca5a5',
                'contested' => '#fdba74',
                'watch' => '#fde68a',
                'safe' => '#86efac',
            ];
            $tierBg = [
                'strategic' => 'rgba(216,180,254,0.10)',
                'hot' => 'rgba(252,165,165,0.10)',
                'contested' => 'rgba(253,186,116,0.10)',
                'watch' => 'rgba(253,230,138,0.10)',
                'safe' => 'rgba(134,239,172,0.10)',
            ];
        @endphp

        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
            <div style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
                <h2 style="margin:0; font-size:1.05rem; color:#e5e5e7;">{{ $viewer_bloc_name }} <span style="font-weight:400; color:#7a7a82; font-size:0.75rem;">· system threat surface</span></h2>
                <span style="font-size:0.6rem; color:#7a7a82; margin-left:auto; font-style:italic;">window ending {{ $latest_date ?? '—' }} · advisory · log-derived</span>
                <x-intel-freshness surface="threat_surface"
                    :timestamp="$latest_computed_at ?? null" />
                @php /* end-of-day baseline if computed_at not provided */ @endphp
            </div>
            <p style="font-size:0.78rem; color:#9ca3af; margin-top:0.4rem; margin-bottom:0;">
                Composite per-system threat from hostile cluster frequency, escalation density, battle linkage, operational density, reliability-weighted reports, and corridor centrality. Tier thresholds: strategic ≥7, hot ≥4, contested ≥2, watch ≥0.5.
            </p>
        </div>

        {{-- Tier strip --}}
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:0.5rem; margin-bottom:1rem;">
            @foreach (['strategic','hot','contested','watch','safe'] as $tier)
                @php $count = $by_tier[$tier] ?? 0; @endphp
                <a href="?tier={{ $tier }}" class="fi-section rounded-xl shadow-sm" style="text-decoration:none; padding:0.6rem; text-align:center; background:{{ $tierBg[$tier] }}; border:1px solid {{ $tierColors[$tier] }}33;">
                    <div style="font-size:0.55rem; text-transform:uppercase; letter-spacing:0.08em; color:{{ $tierColors[$tier] }};">{{ $tier }}</div>
                    <div style="font-size:1.4rem; font-weight:600; color:#e5e5e7; margin-top:0.2rem;">{{ $count }}</div>
                    <div style="font-size:0.55rem; color:#6b7280; margin-top:0.1rem;">systems</div>
                </a>
            @endforeach
        </div>

        <div style="display:grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap:1rem;">
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; margin-bottom:0.5rem;">
                    <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0;">
                        Top systems · {{ count($rows) }}
                    </h3>
                    @if ($tier_filter || $region_filter)
                        <a href="/portal/operations/heatmap" style="font-size:0.6rem; color:#7a7a82; text-decoration:none;">[clear filters]</a>
                    @endif
                </div>
                @if (count($rows) === 0)
                    <p style="font-size:0.78rem; color:#9ca3af;">No data.</p>
                @else
                    <table style="width:100%; border-collapse:collapse; font-size:0.72rem;">
                        <thead>
                            <tr style="background:rgba(255,255,255,0.03); color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em; font-size:0.55rem;">
                                <th style="text-align:left; padding:0.4rem 0.5rem;">System</th>
                                <th style="text-align:left; padding:0.4rem 0.5rem;">Region</th>
                                <th style="text-align:left; padding:0.4rem 0.5rem;">Tier</th>
                                <th style="text-align:right; padding:0.4rem 0.5rem;">Score</th>
                                <th style="text-align:right; padding:0.4rem 0.5rem;">Cluster</th>
                                <th style="text-align:right; padding:0.4rem 0.5rem;">Esc</th>
                                <th style="text-align:right; padding:0.4rem 0.5rem;">Battle</th>
                                <th style="text-align:right; padding:0.4rem 0.5rem;">Reliab</th>
                                <th style="text-align:right; padding:0.4rem 0.5rem;">Corridor</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr style="border-top:1px solid rgba(255,255,255,0.04);">
                                    <td style="padding:0.35rem 0.5rem; color:#e5e5e7;">{{ $r->solar_system_name ?? '#'.$r->solar_system_id }}</td>
                                    <td style="padding:0.35rem 0.5rem; color:#9ca3af;">{{ $r->region_name ?? '—' }}</td>
                                    <td style="padding:0.35rem 0.5rem; color:{{ $tierColors[$r->tier] ?? '#9ca3af' }}; text-transform:uppercase; letter-spacing:0.06em; font-size:0.55rem;">{{ $r->tier }}</td>
                                    <td style="padding:0.35rem 0.5rem; text-align:right; color:#fdba74; font-weight:600;">{{ number_format((float) $r->threat_score, 2) }}</td>
                                    <td style="padding:0.35rem 0.5rem; text-align:right; color:#cbd5e1;">{{ number_format((float) $r->hostile_cluster_score, 1) }}</td>
                                    <td style="padding:0.35rem 0.5rem; text-align:right; color:#cbd5e1;">{{ number_format((float) $r->escalation_score, 1) }}</td>
                                    <td style="padding:0.35rem 0.5rem; text-align:right; color:#cbd5e1;">{{ number_format((float) $r->battle_linkage_score, 1) }}</td>
                                    <td style="padding:0.35rem 0.5rem; text-align:right; color:#cbd5e1;">{{ number_format((float) $r->reliability_score, 1) }}</td>
                                    <td style="padding:0.35rem 0.5rem; text-align:right; color:#cbd5e1;">{{ number_format((float) $r->corridor_centrality_score, 1) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div style="display:grid; gap:0.75rem;">
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                        Regions · top by max threat
                    </h3>
                    <div style="display:grid; gap:0.25rem; max-height:280px; overflow:auto;">
                        @foreach ($by_region as $r)
                            <a href="?region={{ $r->region_name }}" style="display:flex; gap:0.4rem; align-items:center; text-decoration:none; padding:0.3rem 0.5rem; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:4px; color:#e5e5e7; font-size:0.7rem;">
                                <span style="flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $r->region_name ?? '—' }}</span>
                                <span style="font-size:0.55rem; color:#fdba74;">top {{ number_format((float) $r->top, 1) }}</span>
                                <span style="font-size:0.55rem; color:#fca5a5;">{{ $r->hotcount ?? 0 }} hot</span>
                                <span style="font-size:0.55rem; color:#7a7a82;">{{ $r->n }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>

                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">
                        Top hostile travel lanes
                    </h3>
                    @if (count($corridors) === 0)
                        <p style="font-size:0.7rem; color:#7a7a82; font-style:italic;">No corridors yet.</p>
                    @else
                        <div style="display:grid; gap:0.25rem; max-height:360px; overflow:auto;">
                            @foreach ($corridors as $c)
                                <div style="padding:0.3rem 0.5rem; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:4px; font-size:0.7rem;">
                                    <div style="display:flex; gap:0.4rem; align-items:center;">
                                        <span style="color:#86efac;">{{ $c->from_system_name }}</span>
                                        <span style="color:#7a7a82;">→</span>
                                        <span style="color:#86efac;">{{ $c->to_system_name }}</span>
                                        <span style="margin-left:auto; font-size:0.55rem; color:{{ $c->confidence === 'high' ? '#86efac' : '#fde68a' }}; text-transform:uppercase; letter-spacing:0.06em;">{{ $c->confidence }}</span>
                                    </div>
                                    <div style="font-size:0.55rem; color:#7a7a82; margin-top:0.15rem;">
                                        ×{{ $c->transition_count }} transitions · {{ $c->distinct_characters }} chars
                                        @if ($c->avg_transition_seconds)
                                            · avg {{ (int) round($c->avg_transition_seconds / 60) }}m
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>

<x-filament-panels::page>
    @php
        $bandStyle = [
            'critical' => ['bg' => 'rgba(239,68,68,0.18)', 'fg' => '#fca5a5', 'border' => 'rgba(239,68,68,0.4)'],
            'high'     => ['bg' => 'rgba(251,146,60,0.15)', 'fg' => '#fdba74', 'border' => 'rgba(251,146,60,0.4)'],
            'elevated' => ['bg' => 'rgba(251,191,36,0.15)', 'fg' => '#fcd34d', 'border' => 'rgba(251,191,36,0.35)'],
            'leadership_exempt' => ['bg' => 'rgba(148,163,184,0.1)', 'fg' => '#94a3b8', 'border' => 'rgba(148,163,184,0.25)'],
            'below_threshold' => ['bg' => 'rgba(148,163,184,0.08)', 'fg' => '#cbd5e1', 'border' => 'rgba(148,163,184,0.25)'],
            'cohort_unavailable' => ['bg' => 'rgba(100,116,139,0.1)', 'fg' => '#94a3b8', 'border' => 'rgba(100,116,139,0.25)'],
            'insufficient_history' => ['bg' => 'rgba(100,116,139,0.1)', 'fg' => '#94a3b8', 'border' => 'rgba(100,116,139,0.25)'],
        ];
        $fmtPct = fn ($v) => $v === null ? '—' : number_format((float) $v * 100, 0) . '%';
    @endphp

    @if (! empty($no_cids))
        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p style="font-size:0.82rem; color:#7a7a82;">Pass <code>?cids=ID1,ID2,ID3</code> to compare up to 4 pilots side by side.</p>
        </div>
    @elseif (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p style="font-size:0.82rem; color:#7a7a82;">No viewer bloc detected.</p>
        </div>
    @else
        <div style="display:grid; grid-template-columns: repeat({{ count($dossiers) }}, 1fr); gap:1rem;">
            @foreach ($dossiers as $d)
                @php
                    $band = $d['anomaly']['review_priority_band'] ?? 'cohort_unavailable';
                    $style = $bandStyle[$band] ?? $bandStyle['below_threshold'];
                @endphp
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                     style="padding:1rem; border-top:3px solid {{ $style['border'] }};">
                    @if ($d['not_found'] ?? false)
                        <p style="font-size:0.82rem; color:#7a7a82;">No data for #{{ $d['character_id'] }}</p>
                    @else
                        <div style="display:flex; gap:0.7rem; align-items:center;">
                            <img src="https://images.evetech.net/characters/{{ $d['character_id'] }}/portrait?size=64"
                                 referrerpolicy="no-referrer" style="width:48px; height:48px; border-radius:6px; border:2px solid {{ $style['border'] }};" alt="">
                            <div style="min-width:0; flex:1;">
                                <div style="font-weight:600; color:#e5e5e7; font-size:0.9rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                    <a href="/admin/counter-intel/{{ $d['character_id'] }}" style="color:inherit; text-decoration:none;">{{ $d['character_name'] }}</a>
                                </div>
                                <div style="font-size:0.68rem; color:{{ $style['fg'] }}; text-transform:uppercase; letter-spacing:0.08em;">
                                    {{ str_replace('_', ' ', $band) }}
                                    @if (! empty($d['anomaly']['review_priority_score']))
                                        · score {{ number_format((float) $d['anomaly']['review_priority_score'], 2) }}
                                    @endif
                                </div>
                            </div>
                        </div>
                        <table style="width:100%; margin-top:0.8rem; font-size:0.75rem; border-collapse:collapse;">
                            <tr><td style="color:#9ca3af; padding:0.2rem 0;">Hostile history</td>
                                <td style="text-align:right; color:#fca5a5; font-family:'JetBrains Mono',monospace;">{{ $fmtPct($d['anomaly']['affiliation_anomaly_pct'] ?? null) }}</td></tr>
                            <tr><td style="color:#9ca3af; padding:0.2rem 0;">Hostile co-flight</td>
                                <td style="text-align:right; color:#fca5a5; font-family:'JetBrains Mono',monospace;">{{ $fmtPct($d['anomaly']['hostile_overlap_pct'] ?? null) }}</td></tr>
                            <tr><td style="color:#9ca3af; padding:0.2rem 0;">Bridge</td>
                                <td style="text-align:right; color:#a5b4fc; font-family:'JetBrains Mono',monospace;">{{ $fmtPct($d['anomaly']['bridge_anomaly_pct'] ?? null) }}</td></tr>
                            <tr><td style="color:#9ca3af; padding:0.2rem 0;">Recent join</td>
                                <td style="text-align:right; color:{{ ($d['anomaly']['recent_hostile_join'] ?? 0) ? '#f87171' : '#475569' }}; font-family:'JetBrains Mono',monospace;">
                                    {{ ($d['anomaly']['recent_hostile_join'] ?? 0) ? 'Yes' : '—' }}
                                </td></tr>
                            <tr><td style="color:#9ca3af; padding:0.2rem 0;">Ring</td>
                                <td style="text-align:right; color:#e5e5e7; font-family:'JetBrains Mono',monospace;">
                                    {{ $d['anomaly']['ring_id'] ?? '—' }}
                                    @if (! empty($d['anomaly']['ring_size'])) <span style="color:#7a7a82;">({{ $d['anomaly']['ring_size'] }})</span>@endif
                                </td></tr>
                            <tr><td style="color:#9ca3af; padding:0.2rem 0;">Cohort</td>
                                <td style="text-align:right; color:#9ca3af; font-family:'JetBrains Mono',monospace;">
                                    {{ $d['anomaly']['cohort_confidence'] ?? '—' }}
                                    @if (! empty($d['anomaly']['cohort_size'])) <span style="color:#7a7a82;">· {{ $d['anomaly']['cohort_size'] }}</span>@endif
                                </td></tr>
                        </table>

                        @if (! empty($d['hostile_alliances_in_history']))
                            <div style="margin-top:0.6rem; font-size:0.68rem;">
                                <div style="color:#9ca3af; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:0.2rem;">Hostile alliances in history</div>
                                <div style="color:#fca5a5;">{{ implode(', ', array_column($d['hostile_alliances_in_history'], 'alliance_name')) }}</div>
                            </div>
                        @endif

                        <a href="/admin/counter-intel/{{ $d['character_id'] }}"
                           style="margin-top:0.7rem; display:inline-block; font-size:0.72rem; color:#4fd0d0; text-decoration:none;">
                            open full dossier →
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>

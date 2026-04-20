<x-filament-panels::page>
    @php
        $bandStyle = [
            'critical' => ['bg' => 'rgba(239,68,68,0.18)', 'fg' => '#fca5a5', 'border' => 'rgba(239,68,68,0.4)'],
            'high'     => ['bg' => 'rgba(251,146,60,0.15)', 'fg' => '#fdba74', 'border' => 'rgba(251,146,60,0.4)'],
            'elevated' => ['bg' => 'rgba(251,191,36,0.15)', 'fg' => '#fcd34d', 'border' => 'rgba(251,191,36,0.35)'],
            'below_threshold'      => ['bg' => 'rgba(148,163,184,0.08)', 'fg' => '#cbd5e1', 'border' => 'rgba(148,163,184,0.25)'],
            'cohort_unavailable'   => ['bg' => 'rgba(100,116,139,0.1)', 'fg' => '#94a3b8', 'border' => 'rgba(100,116,139,0.25)'],
            'insufficient_history' => ['bg' => 'rgba(100,116,139,0.1)', 'fg' => '#94a3b8', 'border' => 'rgba(100,116,139,0.25)'],
        ];
        $fmtPct = fn ($v) => $v === null ? '—' : number_format((float) $v * 100, 0).'%';
    @endphp

    @if ($no_bloc)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                No viewer bloc detected. Link an EVE character under <a href="/portal/account-settings" class="text-primary-500 underline">Account settings</a>,
                or append <code>?bloc_id=N</code> to this URL to review on behalf of a specific coalition.
            </p>
        </div>
    @else
        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
            <div style="display:flex; gap:1rem; align-items:baseline; justify-content:space-between; flex-wrap:wrap;">
                <h2 class="text-lg font-semibold">Review priority · {{ $viewer_bloc_name }}</h2>
                <div style="font-size:0.7rem; color:#7a7a82; font-style:italic;">
                    Triage surface, not automation. Every row invites manual review. Scoring is character-relative, not bloc-relative.
                </div>
            </div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap:0.6rem; margin-top:0.75rem;">
                @foreach (['critical','high','elevated','below_threshold','cohort_unavailable','insufficient_history'] as $b)
                    @php $style = $bandStyle[$b]; $count = $band_counts[$b] ?? 0; $active = ($band_filter === $b); @endphp
                    <a href="?bandFilter={{ $b }}"
                       style="text-decoration:none; display:block;
                              background:{{ $style['bg'] }};
                              border:1px solid {{ $style['border'] }};
                              {{ $active ? 'outline:2px solid '.$style['fg'].';' : '' }}
                              border-radius:6px; padding:0.55rem 0.75rem; color:{{ $style['fg'] }};">
                        <div style="font-size:0.64rem; text-transform:uppercase; letter-spacing:0.08em; opacity:0.85;">{{ str_replace('_', ' ', $b) }}</div>
                        <div style="font-size:1.15rem; font-weight:700; margin-top:0.1rem;">{{ number_format($count) }}</div>
                    </a>
                @endforeach
            </div>
            @if ($band_filter)
                <div style="margin-top:0.75rem; font-size:0.78rem;">
                    Filter active: <strong>{{ $band_filter }}</strong> · <a href="?" class="text-primary-500 underline">clear</a>
                </div>
            @endif
        </div>

        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            @if (empty($rows))
                <p style="font-size:0.8rem; color:#7a7a82; font-style:italic;">No characters match the current filter.</p>
            @else
                @php
                    // Pick the single strongest driver for a 1-line reason.
                    // Threshold-pick so directors can scan "why" without
                    // re-reading four percentiles per row.
                    $reasonFor = function (array $r): array {
                        $ranks = [];
                        if (($r['affiliation_anomaly_pct'] ?? 0) >= 0.85) $ranks[] = [$r['affiliation_anomaly_pct'], 'hostile history'];
                        if (($r['hostile_overlap_pct'] ?? 0) >= 0.85) $ranks[] = [$r['hostile_overlap_pct'], 'co-flies with hostiles'];
                        if (($r['bridge_anomaly_pct'] ?? 0) >= 0.95) $ranks[] = [$r['bridge_anomaly_pct'], 'cross-cluster bridge'];
                        if (! empty($r['recent_hostile_join'])) $ranks[] = [0.95, 'recent hostile join'];
                        if (! $ranks) return [null, '—'];
                        usort($ranks, fn ($a, $b) => $b[0] <=> $a[0]);
                        return [$ranks[0][0], $ranks[0][1]];
                    };
                @endphp
                <table style="width:100%; font-size:0.8rem; border-collapse:collapse; font-variant-numeric: tabular-nums;">
                    <thead>
                        <tr style="text-align:left; color:#7a7a82; text-transform:uppercase; font-size:0.62rem; letter-spacing:0.08em;">
                            <th style="padding:0.35rem 0.5rem;">Character</th>
                            <th style="padding:0.35rem 0.5rem;">Reason</th>
                            <th style="padding:0.35rem 0.5rem;">Role</th>
                            <th style="padding:0.35rem 0.5rem; text-align:right;">Affil</th>
                            <th style="padding:0.35rem 0.5rem; text-align:right;">Hostile</th>
                            <th style="padding:0.35rem 0.5rem; text-align:right;">Bridge</th>
                            <th style="padding:0.35rem 0.5rem; text-align:center;">Conf.</th>
                            <th style="padding:0.35rem 0.5rem; text-align:right;">Score</th>
                            <th style="padding:0.35rem 0.5rem;">Band</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            @php
                                $style = $bandStyle[$r['review_priority_band']] ?? $bandStyle['below_threshold'];
                                [, $reason] = $reasonFor($r);
                            @endphp
                            <tr style="border-top:1px solid rgba(255,255,255,0.05); cursor:pointer;"
                                onclick="window.location='/admin/counter-intel/{{ $r['character_id'] }}'">
                                <td style="padding:0.4rem 0.5rem; color:#e5e5e7;">
                                    <span style="display:inline-flex; align-items:center; gap:0.45rem;">
                                        <img src="https://images.evetech.net/characters/{{ $r['character_id'] }}/portrait?size=32"
                                             referrerpolicy="no-referrer"
                                             style="width:22px; height:22px; border-radius:50%;" alt="">
                                        {{ $r['character_name'] ?? "Pilot #{$r['character_id']}" }}
                                    </span>
                                </td>
                                <td style="padding:0.4rem 0.5rem; color:#fca5a5; font-size:0.72rem;">{{ $reason }}</td>
                                <td style="padding:0.4rem 0.5rem; color:#9ca3af;">{{ $r['dominant_role'] ?? '—' }}</td>
                                <td style="padding:0.4rem 0.5rem; text-align:right; color:#fca5a5;">{{ $fmtPct($r['affiliation_anomaly_pct']) }}</td>
                                <td style="padding:0.4rem 0.5rem; text-align:right; color:#fca5a5;">{{ $fmtPct($r['hostile_overlap_pct']) }}</td>
                                <td style="padding:0.4rem 0.5rem; text-align:right; color:#a5b4fc;">{{ $fmtPct($r['bridge_anomaly_pct']) }}</td>
                                <td style="padding:0.4rem 0.5rem; text-align:center; color:#9ca3af; font-size:0.7rem;">{{ $r['cohort_confidence'] }}</td>
                                <td style="padding:0.4rem 0.5rem; text-align:right; color:#e5e5e7; font-weight:600;">{{ number_format((float) $r['review_priority_score'], 2) }}</td>
                                <td style="padding:0.4rem 0.5rem;">
                                    <span style="display:inline-block; padding:1px 7px; border-radius:10px; font-size:0.62rem; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; background:{{ $style['bg'] }}; color:{{ $style['fg'] }}; border:1px solid {{ $style['border'] }};">
                                        {{ str_replace('_', ' ', $r['review_priority_band']) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif
</x-filament-panels::page>

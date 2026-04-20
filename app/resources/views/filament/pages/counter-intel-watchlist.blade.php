<x-filament-panels::page>
    @php
        $bandStyle = [
            'critical' => ['bg' => 'rgba(239,68,68,0.18)', 'fg' => '#fca5a5', 'border' => 'rgba(239,68,68,0.4)'],
            'high'     => ['bg' => 'rgba(251,146,60,0.15)', 'fg' => '#fdba74', 'border' => 'rgba(251,146,60,0.4)'],
            'elevated' => ['bg' => 'rgba(251,191,36,0.15)', 'fg' => '#fcd34d', 'border' => 'rgba(251,191,36,0.35)'],
            'leadership_exempt' => ['bg' => 'rgba(148,163,184,0.1)', 'fg' => '#94a3b8', 'border' => 'rgba(148,163,184,0.25)'],
            'below_threshold' => ['bg' => 'rgba(148,163,184,0.08)', 'fg' => '#cbd5e1', 'border' => 'rgba(148,163,184,0.25)'],
        ];
        $fmtPct = fn ($v) => $v === null ? '—' : number_format((float) $v * 100, 0).'%';
    @endphp

    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div style="display:flex; gap:1rem; align-items:baseline; justify-content:space-between; margin-bottom:0.75rem;">
            <h2 class="text-lg font-semibold">Watchlist · {{ $count }} saved pilots</h2>
            <a href="?export=csv" class="text-primary-500 underline" style="font-size:0.78rem;">export CSV</a>
        </div>

        @if ($count === 0)
            <p style="font-size:0.8rem; color:#7a7a82; font-style:italic;">No pilots on your watchlist. Add one from any <a href="/admin/counter-intel" class="text-primary-500 underline">counter-intel dossier</a>.</p>
        @else
            <table style="width:100%; font-size:0.8rem; border-collapse:collapse; font-variant-numeric: tabular-nums;">
                <thead>
                    <tr style="text-align:left; color:#7a7a82; text-transform:uppercase; font-size:0.62rem; letter-spacing:0.08em;">
                        <th style="padding:0.35rem 0.5rem;">Character</th>
                        <th style="padding:0.35rem 0.5rem;">Band</th>
                        <th style="padding:0.35rem 0.5rem; text-align:right;">Score</th>
                        <th style="padding:0.35rem 0.5rem; text-align:right;">Hostile</th>
                        <th style="padding:0.35rem 0.5rem; text-align:right;">Affil</th>
                        <th style="padding:0.35rem 0.5rem; text-align:right;">Bridge</th>
                        <th style="padding:0.35rem 0.5rem;">Note</th>
                        <th style="padding:0.35rem 0.5rem;">Added</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $r)
                        @php
                            $band = $r['review_priority_band'] ?? '—';
                            $s = $bandStyle[$band] ?? $bandStyle['below_threshold'];
                        @endphp
                        <tr style="border-top:1px solid rgba(255,255,255,0.05); cursor:pointer;"
                            onclick="window.location='/admin/counter-intel/{{ $r['character_id'] }}'">
                            <td style="padding:0.4rem 0.5rem; color:#e5e5e7;">
                                <span style="display:inline-flex; align-items:center; gap:0.45rem;">
                                    <img src="https://images.evetech.net/characters/{{ $r['character_id'] }}/portrait?size=32"
                                         referrerpolicy="no-referrer" style="width:22px; height:22px; border-radius:50%;" alt="">
                                    {{ $r['character_name'] ?? "Pilot #{$r['character_id']}" }}
                                </span>
                            </td>
                            <td style="padding:0.4rem 0.5rem;">
                                <span style="display:inline-block; padding:1px 7px; border-radius:10px; font-size:0.62rem; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; background:{{ $s['bg'] }}; color:{{ $s['fg'] }}; border:1px solid {{ $s['border'] }};">
                                    {{ str_replace('_', ' ', $band) }}
                                </span>
                            </td>
                            <td style="padding:0.4rem 0.5rem; text-align:right; color:#e5e5e7;">{{ $r['review_priority_score'] !== null ? number_format((float) $r['review_priority_score'], 2) : '—' }}</td>
                            <td style="padding:0.4rem 0.5rem; text-align:right; color:#fca5a5;">{{ $fmtPct($r['hostile_overlap_pct']) }}</td>
                            <td style="padding:0.4rem 0.5rem; text-align:right; color:#fca5a5;">{{ $fmtPct($r['affiliation_anomaly_pct']) }}</td>
                            <td style="padding:0.4rem 0.5rem; text-align:right; color:#a5b4fc;">{{ $fmtPct($r['bridge_anomaly_pct']) }}</td>
                            <td style="padding:0.4rem 0.5rem; color:#9ca3af; font-style:italic;">{{ $r['note'] ?? '—' }}</td>
                            <td style="padding:0.4rem 0.5rem; color:#7a7a82; font-size:0.72rem;">{{ \Carbon\Carbon::parse($r['added_at'])->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-filament-panels::page>

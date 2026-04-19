<x-filament-panels::page>
    @php
        $labelStyle = [
            'aligned'                   => ['bg' => 'rgba(34,197,94,0.18)',  'fg' => '#86efac', 'border' => 'rgba(34,197,94,0.4)'],
            'loosely coordinated'       => ['bg' => 'rgba(74,222,128,0.12)', 'fg' => '#bbf7d0', 'border' => 'rgba(74,222,128,0.3)'],
            'hostile'                   => ['bg' => 'rgba(239,68,68,0.2)',   'fg' => '#fca5a5', 'border' => 'rgba(239,68,68,0.4)'],
            'conditionally aligned'     => ['bg' => 'rgba(251,191,36,0.15)', 'fg' => '#fcd34d', 'border' => 'rgba(251,191,36,0.35)'],
            'neutral'                   => ['bg' => 'rgba(148,163,184,0.1)', 'fg' => '#cbd5e1', 'border' => 'rgba(148,163,184,0.3)'],
            'insufficient observations' => ['bg' => 'rgba(100,116,139,0.08)','fg' => '#94a3b8', 'border' => 'rgba(100,116,139,0.2)'],
        ];
        $fmtPct = fn ($v) => number_format((float) $v * 100, 0).'%';
        $fmtDelta = fn ($v) => ($v >= 0 ? '+' : '').number_format((float) $v * 100, 0).'pp';
    @endphp

    @if ($no_data)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                No pair-behavior data yet. Run <code>make bloc-intel-extract</code> to populate.
            </p>
        </div>
    @else
        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
            <div style="display:flex; gap:1rem; align-items:baseline; justify-content:space-between; flex-wrap:wrap;">
                <div>
                    <h2 class="text-lg font-semibold">Alliance picker</h2>
                    <p style="font-size:0.68rem; color:#7a7a82; margin-top:0.1rem;">
                        Window ending {{ $window_end }} · derived from 90 days of killmails · recency decay half-life 30 days.
                    </p>
                </div>
                <form method="get" style="display:flex; gap:0.5rem; align-items:center;">
                    <input type="text" name="q" value="{{ $alliance_search }}" placeholder="Search alliance name…"
                           style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.1); color:#e5e5e7; padding:0.35rem 0.6rem; border-radius:4px; font-size:0.8rem; width:220px;">
                    <button type="submit" style="background:#4338ca; color:#fff; border:none; padding:0.35rem 0.75rem; border-radius:4px; font-size:0.75rem; cursor:pointer;">Search</button>
                    @if ($alliance_search || $alliance)
                        <a href="?" style="font-size:0.72rem; color:#7a7a82;">clear</a>
                    @endif
                </form>
            </div>

            @if (! $alliance)
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap:0.4rem; margin-top:0.75rem;">
                    @foreach ($suggestions as $s)
                        <a href="?alliance_id={{ $s['alliance_id'] }}"
                           style="text-decoration:none; display:flex; justify-content:space-between; align-items:center;
                                  background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08);
                                  border-radius:5px; padding:0.45rem 0.7rem; color:#e5e5e7; font-size:0.8rem;">
                            <span>{{ $s['name'] ?? "Alliance #{$s['alliance_id']}" }}</span>
                            <span style="font-size:0.7rem; color:#7a7a82;">{{ number_format((int) $s['total_n_obs']) }} obs</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($alliance)
            <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
                <div style="display:flex; gap:0.8rem; align-items:center;">
                    <img src="https://images.evetech.net/alliances/{{ $alliance['id'] }}/logo?size=64"
                         referrerpolicy="no-referrer"
                         style="width:42px; height:42px; border-radius:6px;" alt="">
                    <div>
                        <div style="font-size:1.05rem; font-weight:700; color:#e5e5e7;">{{ $alliance['name'] }}</div>
                        <div style="font-size:0.72rem; color:#9ca3af;">
                            @if ($alliance['bloc'])
                                <span style="color:#86efac;">Ground truth: {{ $alliance['bloc'] }}{{ $alliance['role'] ? ' · '.$alliance['role'] : '' }}</span>
                            @else
                                <span style="color:#fcd34d;">No ground-truth bloc label — showing pure inference</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                @if (empty($pairs))
                    <p style="font-size:0.8rem; color:#7a7a82; font-style:italic;">No counterpart pairs above the noise floor. Alliance may be too quiet in the current window.</p>
                @else
                    <table style="width:100%; font-size:0.8rem; border-collapse:collapse;">
                        <thead>
                            <tr style="text-align:left; color:#7a7a82; text-transform:uppercase; font-size:0.62rem; letter-spacing:0.08em;">
                                <th style="padding:0.35rem 0.5rem;">Counterpart</th>
                                <th style="padding:0.35rem 0.5rem;">Ground truth</th>
                                <th style="padding:0.35rem 0.5rem;">Inferred</th>
                                <th style="padding:0.35rem 0.5rem; text-align:right;">Affinity</th>
                                <th style="padding:0.35rem 0.5rem; text-align:right;">Hostility</th>
                                <th style="padding:0.35rem 0.5rem; text-align:right;">n obs</th>
                                <th style="padding:0.35rem 0.5rem; text-align:center;">Conf.</th>
                                <th style="padding:0.35rem 0.5rem;">Conditional triggers (top)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pairs as $p)
                                @php $style = $labelStyle[$p['label']] ?? $labelStyle['neutral']; @endphp
                                <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                    <td style="padding:0.4rem 0.5rem; color:#e5e5e7;">
                                        <span style="display:inline-flex; align-items:center; gap:0.45rem;">
                                            <img src="https://images.evetech.net/alliances/{{ $p['counterpart_id'] }}/logo?size=32"
                                                 referrerpolicy="no-referrer"
                                                 style="width:18px; height:18px; border-radius:3px;" alt="">
                                            <a href="?alliance_id={{ $p['counterpart_id'] }}"
                                               style="color:#e5e5e7; text-decoration:none;">{{ $p['counterpart_name'] }}</a>
                                        </span>
                                    </td>
                                    <td style="padding:0.4rem 0.5rem; font-size:0.7rem; color:#9ca3af;">
                                        {{ $p['counterpart_bloc'] ?? '—' }}
                                    </td>
                                    <td style="padding:0.4rem 0.5rem;">
                                        <span style="display:inline-block; padding:1px 7px; border-radius:10px; font-size:0.62rem; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; background:{{ $style['bg'] }}; color:{{ $style['fg'] }}; border:1px solid {{ $style['border'] }};">
                                            {{ $p['label'] }}
                                        </span>
                                    </td>
                                    <td style="padding:0.4rem 0.5rem; text-align:right; color:#86efac;">{{ $fmtPct($p['affinity']) }}</td>
                                    <td style="padding:0.4rem 0.5rem; text-align:right; color:#fca5a5;">{{ $fmtPct($p['hostility']) }}</td>
                                    <td style="padding:0.4rem 0.5rem; text-align:right; color:#cbd5e1;">{{ number_format($p['n_obs']) }}</td>
                                    <td style="padding:0.4rem 0.5rem; text-align:center; color:#9ca3af; font-size:0.7rem;">{{ $fmtPct($p['confidence']) }}</td>
                                    <td style="padding:0.4rem 0.5rem; font-size:0.7rem; color:#9ca3af;">
                                        @if (! empty($triggers[$p['counterpart_id']] ?? []))
                                            @foreach ($triggers[$p['counterpart_id']] as $t)
                                                <div style="white-space:nowrap;">
                                                    @if ($t['delta'] >= 0.1)
                                                        <span style="color:#86efac;">+</span>
                                                    @elseif ($t['delta'] <= -0.1)
                                                        <span style="color:#fca5a5;">−</span>
                                                    @else
                                                        <span style="color:#7a7a82;">•</span>
                                                    @endif
                                                    <strong style="color:#e5e5e7;">{{ $t['trigger_name'] }}</strong>
                                                    <span style="color:#7a7a82;">({{ $fmtDelta($t['delta']) }})</span>
                                                </div>
                                            @endforeach
                                        @else
                                            <span style="color:#475569;">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif
    @endif
</x-filament-panels::page>

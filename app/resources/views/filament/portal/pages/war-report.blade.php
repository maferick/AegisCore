<x-filament-panels::page>
    @php
        $fmtIsk = function (float $v): string {
            if ($v >= 1e12) return number_format($v / 1e12, 2) . ' T';
            if ($v >= 1e9)  return number_format($v / 1e9, 2) . ' B';
            if ($v >= 1e6)  return number_format($v / 1e6, 2) . ' M';
            return number_format($v, 0);
        };
        $fmtNum = fn ($n) => number_format((int) $n);
        $sevColor = function (?float $sec): string {
            if ($sec === null) return '#9ca3af';
            if ($sec >= 0.5) return '#86efac';
            if ($sec >= 0.0) return '#fdba74';
            return '#fca5a5';
        };
        $tiles = [
            'wc'   => ['label' => 'WinterCo losses',          'tint' => '#86efac', 'count' => $totals['wc']['kms'],   'isk' => $totals['wc']['isk']],
            'goon' => ['label' => 'Goonswarm Federation losses', 'tint' => '#fca5a5', 'count' => $totals['goon']['kms'], 'isk' => $totals['goon']['isk']],
            'init' => ['label' => 'The Initiative. losses',   'tint' => '#fdba74', 'count' => $totals['init']['kms'], 'isk' => $totals['init']['isk']],
        ];
        $totalKms = $totals['wc']['kms'] + $totals['goon']['kms'] + $totals['init']['kms'];
        $totalIsk = $totals['wc']['isk'] + $totals['goon']['isk'] + $totals['init']['isk'];
    @endphp

    {{-- Hero banner --}}
    <div style="position:relative; padding:1.5rem 1.75rem; margin-bottom:1rem; border-radius:10px;
                background:linear-gradient(135deg, rgba(34,197,94,0.08) 0%, rgba(0,0,0,0.4) 50%, rgba(239,68,68,0.10) 100%);
                border:1px solid rgba(255,255,255,0.10); overflow:hidden;">
        <div style="display:flex; gap:2rem; align-items:flex-start; flex-wrap:wrap;">
            <div style="flex:2; min-width:280px;">
                <div style="font-size:0.6rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.12em; margin-bottom:0.4rem;">Active conflict</div>
                <h1 style="margin:0 0 0.4rem 0; font-size:1.5rem; color:#e5e5e7; font-weight:700; letter-spacing:0.02em;">
                    <span style="color:#86efac;">WinterCo</span>
                    <span style="color:#7a7a82; font-weight:400;"> vs </span>
                    <span style="color:#fca5a5;">Goonswarm Federation</span>
                    <span style="color:#7a7a82; font-weight:400;"> + </span>
                    <span style="color:#fdba74;">The Initiative.</span>
                </h1>
                <p style="margin:0; font-size:0.78rem; color:#9ca3af;">
                    Conflict floor <span style="color:#cbd5e1;">{{ \Carbon\Carbon::parse($war_start)->format('Y-m-d') }}</span> ·
                    <span style="color:#cbd5e1;">{{ $total_days }}</span> days running ·
                    <span style="color:#cbd5e1;">{{ $wc_alliance_count }}</span> WinterCo alliances ·
                    rendered window <span style="color:#cbd5e1;">last {{ $days }} day(s)</span>
                </p>
            </div>
            <div style="flex:1; min-width:240px; display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                <div style="padding:0.55rem 0.7rem; border:1px solid rgba(255,255,255,0.08); border-radius:6px; background:rgba(255,255,255,0.02);">
                    <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">Total killmails</div>
                    <div style="font-size:1.4rem; color:#e5e5e7; font-weight:600;">{{ $fmtNum($totalKms) }}</div>
                </div>
                <div style="padding:0.55rem 0.7rem; border:1px solid rgba(255,255,255,0.08); border-radius:6px; background:rgba(255,255,255,0.02);">
                    <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">ISK destroyed</div>
                    <div style="font-size:1.4rem; color:#fde68a; font-weight:600;">{{ $fmtIsk($totalIsk) }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Window selector --}}
    <div style="display:flex; gap:0.4rem; align-items:center; margin-bottom:0.75rem; flex-wrap:wrap; font-size:0.7rem; color:#9ca3af;">
        <span style="text-transform:uppercase; letter-spacing:0.08em; font-size:0.6rem; color:#7a7a82;">render window</span>
        @foreach ([1 => '24h', 3 => '3d', 7 => '7d', 14 => '14d', 30 => '30d', 60 => 'full conflict'] as $d => $lbl)
            @php $isActive = (int) $days === $d; @endphp
            <a href="?days={{ $d }}"
               style="font-size:0.6rem; padding:3px 8px; border-radius:4px; text-decoration:none; text-transform:uppercase; letter-spacing:0.06em;
                      background:{{ $isActive ? 'rgba(99,102,241,0.20)' : 'rgba(255,255,255,0.04)' }};
                      color:{{ $isActive ? '#c7d2fe' : '#9ca3af' }};
                      border:1px solid {{ $isActive ? 'rgba(99,102,241,0.40)' : 'rgba(255,255,255,0.10)' }};">
                {{ $lbl }}
            </a>
        @endforeach
        <span style="margin-left:0.6rem; font-style:italic; color:#7a7a82; font-size:0.6rem;">columns capped at 5,000 rows; pick a tighter window to see more recent only</span>
    </div>

    {{-- System hotspots --}}
    @if (count($hotspots) > 0)
        <div style="margin-bottom:1rem; padding:0.85rem 1rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(255,255,255,0.02);">
            <div style="display:flex; align-items:baseline; gap:0.6rem; margin-bottom:0.5rem;">
                <h2 style="margin:0; font-size:0.85rem; color:#e5e5e7;">System hotspots</h2>
                <span style="font-size:0.6rem; color:#7a7a82;">top systems by war-attributable km · entire conflict</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:0.4rem;">
                @foreach ($hotspots as $h)
                    <div style="padding:0.45rem 0.65rem; border:1px solid rgba(255,255,255,0.06); border-radius:5px; background:rgba(0,0,0,0.20);">
                        <div style="font-size:0.78rem; font-weight:600; color:{{ $sevColor($h->security_status ?? null) }}; letter-spacing:0.02em;">{{ $h->system_name }}</div>
                        <div style="font-size:0.62rem; color:#9ca3af; margin-top:0.15rem;">
                            <strong style="color:#e5e5e7;">{{ $fmtNum($h->km_count) }}</strong> km ·
                            <strong style="color:#fde68a;">{{ $fmtIsk((float) $h->isk_destroyed) }}</strong>
                        </div>
                        <div style="font-size:0.55rem; color:#7a7a82; margin-top:0.1rem;">last {{ \Carbon\Carbon::parse($h->last_km)->diffForHumans() }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Upwell structure timeline --}}
    @if (count($structures) > 0)
        <div style="margin-bottom:1rem; padding:0.85rem 1rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(255,255,255,0.02);">
            <div style="display:flex; align-items:baseline; gap:0.6rem; margin-bottom:0.6rem;">
                <h2 style="margin:0; font-size:0.85rem; color:#e5e5e7;">Upwell structure timeline</h2>
                <span style="font-size:0.6rem; color:#7a7a82;">every structure killmail in the conflict ({{ count($structures) }} total)</span>
            </div>
            <div style="max-height:340px; overflow-y:auto; border:1px solid rgba(255,255,255,0.04); border-radius:5px;">
                <table style="width:100%; font-size:0.7rem; border-collapse:collapse;">
                    <thead style="position:sticky; top:0; background:#0a0d12; z-index:1;">
                        <tr style="text-transform:uppercase; letter-spacing:0.06em; font-size:0.55rem; color:#7a7a82;">
                            <th style="padding:0.4rem 0.6rem; text-align:left;">When</th>
                            <th style="padding:0.4rem 0.6rem; text-align:left;">System</th>
                            <th style="padding:0.4rem 0.6rem; text-align:left;">Side</th>
                            <th style="padding:0.4rem 0.6rem; text-align:left;">Type</th>
                            <th style="padding:0.4rem 0.6rem; text-align:left;">Owner</th>
                            <th style="padding:0.4rem 0.6rem; text-align:right;">ISK</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($structures as $s)
                            @php
                                $sideColor = $s->side === 'wc' ? '#86efac' : ($s->side === 'hostile' ? '#fca5a5' : '#9ca3af');
                                $sideLbl = $s->side === 'wc' ? 'WinterCo' : ($s->side === 'hostile' ? 'Goons/Init' : '—');
                            @endphp
                            <tr style="border-top:1px solid rgba(255,255,255,0.04);">
                                <td style="padding:0.35rem 0.6rem; color:#cbd5e1; white-space:nowrap;">{{ \Carbon\Carbon::parse($s->killed_at)->format('M d H:i') }}</td>
                                <td style="padding:0.35rem 0.6rem; color:#7dd3fc;">{{ $s->system_name }}</td>
                                <td style="padding:0.35rem 0.6rem; color:{{ $sideColor }}; font-size:0.6rem; text-transform:uppercase; letter-spacing:0.06em;">{{ $sideLbl }}</td>
                                <td style="padding:0.35rem 0.6rem; color:#fde68a;">{{ $s->victim_ship_type_name ?: $s->victim_ship_group_name ?: 'Structure' }}</td>
                                <td style="padding:0.35rem 0.6rem; color:#cbd5e1;">{{ $s->victim_alliance_name ?: $s->victim_corp_name ?: '—' }}</td>
                                <td style="padding:0.35rem 0.6rem; text-align:right; color:#fde68a;"><a href="https://zkillboard.com/kill/{{ $s->killmail_id }}/" target="_blank" rel="noopener" style="color:inherit; text-decoration:none;">{{ $fmtIsk((float) $s->total_value) }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- 3-column losses --}}
    <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:0.75rem;">
        @foreach (['wc', 'goon', 'init'] as $key)
            @php
                $col = $tiles[$key];
            @endphp
            <div style="padding:0.6rem 0.7rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(0,0,0,0.20);">
                <div style="display:flex; align-items:baseline; justify-content:space-between; margin-bottom:0.5rem; padding-bottom:0.4rem; border-bottom:1px solid rgba(255,255,255,0.06);">
                    <h3 style="margin:0; font-size:0.78rem; color:{{ $col['tint'] }}; letter-spacing:0.04em;">{{ $col['label'] }}</h3>
                </div>
                <div style="display:flex; gap:0.6rem; font-size:0.65rem; color:#9ca3af; margin-bottom:0.6rem;">
                    <div><strong style="color:#e5e5e7;">{{ $fmtNum($col['count']) }}</strong> total</div>
                    <div><strong style="color:#fde68a;">{{ $fmtIsk((float) $col['isk']) }}</strong> isk</div>
                </div>

                @php $colData = $columns[$key] ?? []; @endphp
                @if (count($colData) === 0)
                    <p style="font-size:0.7rem; color:#9ca3af; font-style:italic;">No losses in this window.</p>
                @else
                    @foreach ($colData as $day => $rows)
                        @php
                            $dayIsk = array_sum(array_map(fn ($r) => (float) $r->total_value, $rows));
                        @endphp
                        <details open style="margin-bottom:0.4rem;">
                            <summary style="cursor:pointer; padding:0.3rem 0.5rem; background:rgba(255,255,255,0.03); border-radius:4px; font-size:0.65rem; color:#cbd5e1; list-style:none;">
                                <strong>{{ $day }}</strong>
                                <span style="color:#7a7a82; margin-left:0.4rem;">{{ count($rows) }} kms · {{ $fmtIsk($dayIsk) }}</span>
                            </summary>
                            <div style="padding:0.25rem 0; max-height:480px; overflow-y:auto;">
                                @foreach ($rows as $r)
                                    <div style="padding:0.3rem 0.45rem; border-bottom:1px solid rgba(255,255,255,0.04); font-size:0.65rem; line-height:1.35;">
                                        <div style="display:flex; gap:0.4rem; align-items:baseline; flex-wrap:wrap;">
                                            <span style="color:#7a7a82; font-size:0.6rem;">{{ \Carbon\Carbon::parse($r->killed_at)->format('H:i') }}</span>
                                            <a href="https://zkillboard.com/kill/{{ $r->killmail_id }}/" target="_blank" rel="noopener" style="color:#fde68a; text-decoration:none; font-weight:600;">{{ $fmtIsk((float) $r->total_value) }}</a>
                                            <span style="color:#7dd3fc;">{{ $r->system_name }}</span>
                                            <span style="color:#cbd5e1; flex:1;">{{ $r->victim_ship_type_name ?: '—' }}</span>
                                        </div>
                                        <div style="color:#9ca3af; font-size:0.6rem; margin-top:0.1rem;">
                                            <span>{{ $r->victim_name ?: 'unknown pilot' }}</span>
                                            <span style="color:#7a7a82;"> · {{ $r->victim_alliance_name ?: 'no alliance' }}</span>
                                            @if ($r->fb_char_name)
                                                <span style="color:#7a7a82;"> · fb </span>
                                                <span>{{ $r->fb_char_name }}</span>
                                                @if ($r->fb_alliance_name)
                                                    <span style="color:#7a7a82;"> ({{ $r->fb_alliance_name }})</span>
                                                @endif
                                                @if ($r->fb_ship_type_name)
                                                    <span style="color:#7a7a82;"> in {{ $r->fb_ship_type_name }}</span>
                                                @endif
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endforeach
                @endif
            </div>
        @endforeach
    </div>

    <p style="margin-top:1rem; font-size:0.6rem; color:#7a7a82; font-style:italic;">
        War-attributable filter: a killmail counts when the victim's alliance is on one side AND ≥ 1 attacker is on the opposing side. Pure killmail aggregation, no scoring or judgment applied.
    </p>
</x-filament-panels::page>

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
                    all charts cover entire conflict
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

    {{-- Leaderboards: most-valuable single kills + pilot/alliance rankings --}}
    @php $lb = $leaderboards ?? []; @endphp
    @if (! empty($lb['most_valuable']))
        <div style="margin-bottom:1rem; padding:0.85rem 1rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(255,255,255,0.02);">
            <div style="display:flex; align-items:baseline; gap:0.6rem; margin-bottom:0.6rem; flex-wrap:wrap;">
                <h2 style="margin:0; font-size:0.85rem; color:#e5e5e7;">Top 10 most valuable single kills</h2>
                <span style="font-size:0.6rem; color:#7a7a82;">conflict-wide · click row → zKill</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:0.4rem;">
                @foreach ($lb['most_valuable'] as $i => $m)
                    @php
                        $sideTint = $m->side === 'wc' ? '#86efac' : ($m->side === 'hostile' ? '#fca5a5' : '#9ca3af');
                        $sideLbl = $m->side === 'wc' ? 'WinterCo' : ($m->side === 'hostile' ? 'Goons/Init' : '—');
                    @endphp
                    <a href="https://zkillboard.com/kill/{{ $m->killmail_id }}/" target="_blank" rel="noopener"
                       style="display:block; padding:0.5rem 0.7rem; border:1px solid rgba(255,255,255,0.06); border-radius:5px; background:rgba(0,0,0,0.20); text-decoration:none; color:inherit;">
                        <div style="display:flex; align-items:baseline; gap:0.4rem; flex-wrap:wrap;">
                            <span style="font-size:0.55rem; color:#7a7a82; min-width:14px;">#{{ $i + 1 }}</span>
                            <span style="font-size:1rem; font-weight:700; color:#fde68a;">{{ $fmtIsk((float) $m->total_value) }}</span>
                            <span style="font-size:0.55rem; color:{{ $sideTint }}; text-transform:uppercase; letter-spacing:0.06em;">{{ $sideLbl }}</span>
                        </div>
                        <div style="font-size:0.7rem; color:#cbd5e1; margin-top:0.15rem;">{{ $m->victim_ship_type_name ?: 'Unknown' }} <span style="color:#7a7a82;">· {{ $m->victim_name ?: '—' }}</span></div>
                        <div style="font-size:0.6rem; color:#7a7a82; margin-top:0.1rem;">
                            {{ $m->victim_alliance_name ?: '—' }} · {{ $m->system_name }} · {{ \Carbon\Carbon::parse($m->killed_at)->format('M d H:i') }}
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Pilot + alliance leaderboards --}}
    @if (! empty($lb))
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:0.6rem; margin-bottom:1rem;">
            @php
                $boards = [
                    ['title' => 'Top 10 pilots — kills', 'rows' => $lb['top_pilots_kills'] ?? [], 'metric' => 'kills', 'second' => 'isk_fb', 'second_fmt' => 'isk', 'tint' => '#86efac', 'sub_label' => 'fb isk'],
                    ['title' => 'Top 10 pilots — losses', 'rows' => $lb['top_pilots_losses'] ?? [], 'metric' => 'losses', 'second' => 'isk_lost', 'second_fmt' => 'isk', 'tint' => '#fca5a5', 'sub_label' => 'isk lost'],
                    ['title' => 'Top 10 alliances — kills', 'rows' => $lb['top_alliance_kills'] ?? [], 'metric' => 'kills', 'second' => null, 'second_fmt' => null, 'tint' => '#86efac', 'sub_label' => null],
                    ['title' => 'Top 10 alliances — losses', 'rows' => $lb['top_alliance_losses'] ?? [], 'metric' => 'losses', 'second' => 'isk_lost', 'second_fmt' => 'isk', 'tint' => '#fca5a5', 'sub_label' => 'isk lost'],
                ];
            @endphp
            @foreach ($boards as $b)
                @if (count($b['rows']) === 0) @continue @endif
                @php
                    $maxMetric = 1;
                    foreach ($b['rows'] as $row) $maxMetric = max($maxMetric, (int) $row->{$b['metric']});
                @endphp
                <div style="padding:0.65rem 0.8rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(0,0,0,0.20);">
                    <h3 style="margin:0 0 0.4rem 0; font-size:0.72rem; color:{{ $b['tint'] }}; letter-spacing:0.04em;">{{ $b['title'] }}</h3>
                    @foreach ($b['rows'] as $i => $row)
                        @php $w = max(2, (int) round(((int) $row->{$b['metric']} / $maxMetric) * 100)); @endphp
                        <div style="display:flex; align-items:center; gap:0.4rem; font-size:0.62rem; padding:0.18rem 0; border-bottom:1px solid rgba(255,255,255,0.04);">
                            <span style="flex:0 0 14px; color:#7a7a82; font-size:0.55rem;">{{ $i + 1 }}</span>
                            <div style="flex:1; min-width:0;">
                                <div style="color:#e5e5e7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="{{ $row->name }}">{{ $row->name }}</div>
                                @if (isset($row->alliance_name))
                                    <div style="color:#7a7a82; font-size:0.55rem;">{{ $row->alliance_name }}</div>
                                @endif
                            </div>
                            <div style="flex:0 0 70px;">
                                <div style="height:8px; background:rgba(255,255,255,0.04); border-radius:2px; overflow:hidden;">
                                    <div style="height:100%; width:{{ $w }}%; background:{{ $b['tint'] }}; opacity:0.65;"></div>
                                </div>
                            </div>
                            <div style="flex:0 0 38px; text-align:right; color:#e5e5e7; font-weight:600;">{{ $fmtNum($row->{$b['metric']}) }}</div>
                            @if ($b['second'] !== null)
                                <div style="flex:0 0 56px; text-align:right; color:#fde68a; font-size:0.55rem;">{{ $fmtIsk((float) ($row->{$b['second']} ?? 0)) }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif

    {{-- Top implant losses (capsule kills with non-zero value) --}}
    @if (count($top_implant_pods ?? []) > 0)
        <div style="margin-bottom:1rem; padding:0.85rem 1rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(255,255,255,0.02);">
            <div style="display:flex; align-items:baseline; gap:0.6rem; margin-bottom:0.6rem; flex-wrap:wrap;">
                <h2 style="margin:0; font-size:0.85rem; color:#e5e5e7;">Top implant losses</h2>
                <span style="font-size:0.6rem; color:#7a7a82;">biggest pods by destroyed-implant value · pods with total_value=0 are clean clones (excluded)</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:0.4rem;">
                @foreach ($top_implant_pods as $p)
                    @php
                        $sideTint = $p->side === 'wc' ? '#86efac' : ($p->side === 'hostile' ? '#fca5a5' : '#9ca3af');
                    @endphp
                    <a href="https://zkillboard.com/kill/{{ $p->killmail_id }}/" target="_blank" rel="noopener"
                       style="display:block; padding:0.5rem 0.7rem; border:1px solid rgba(255,255,255,0.06); border-radius:5px; background:rgba(0,0,0,0.20); text-decoration:none; color:inherit;">
                        <div style="display:flex; align-items:baseline; gap:0.5rem;">
                            <span style="font-size:0.95rem; font-weight:700; color:#fde68a;">{{ $fmtIsk((float) $p->total_value) }}</span>
                            <span style="font-size:0.55rem; color:{{ $sideTint }}; text-transform:uppercase; letter-spacing:0.06em;">{{ $p->side === 'wc' ? 'WinterCo' : ($p->side === 'hostile' ? 'Goons/Init' : '—') }}</span>
                        </div>
                        <div style="font-size:0.65rem; color:#cbd5e1; margin-top:0.15rem;">{{ $p->victim_name ?: 'unknown pilot' }} <span style="color:#7a7a82;">· {{ $p->victim_alliance_name ?: '—' }}</span></div>
                        <div style="font-size:0.6rem; color:#7a7a82; margin-top:0.1rem;">{{ $p->system_name }} · {{ \Carbon\Carbon::parse($p->killed_at)->format('M d H:i') }}</div>
                    </a>
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

    @php
        // Helpers used by the per-side histogram blocks below.
        $maxOf = function (array $rows, string $key): float {
            $m = 0.0;
            foreach ($rows as $r) {
                $v = (float) ($r->{$key} ?? 0);
                if ($v > $m) $m = $v;
            }
            return $m > 0 ? $m : 1.0;
        };
        $barCellW = '8px'; // daily-activity bar fixed cell width
    @endphp

    {{-- Per-side breakdown panels — histograms instead of raw lists --}}
    <div style="display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:0.75rem;">
        @foreach (['wc', 'goon', 'init'] as $key)
            @php
                $col = $tiles[$key];
                $r = $rollups[$key] ?? ['daily' => [], 'ship_groups' => [], 'alliances' => [], 'systems' => [], 'hour_of_day' => []];
                $maxDay = $maxOf($r['daily'], 'kms');
                $maxShip = $maxOf($r['ship_groups'], 'kms');
                $maxAlly = $maxOf($r['alliances'], 'kms');
                $maxSys = $maxOf($r['systems'], 'kms');
                $maxHour = $maxOf($r['hour_of_day'], 'kms');
                $hourMap = [];
                foreach ($r['hour_of_day'] as $h) $hourMap[(int) $h->hr] = (int) $h->kms;
                $recentRows = $recent[$key] ?? [];
            @endphp
            <div style="padding:0.7rem 0.85rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(0,0,0,0.22);">
                {{-- Header --}}
                <div style="display:flex; align-items:baseline; justify-content:space-between; margin-bottom:0.5rem; padding-bottom:0.4rem; border-bottom:1px solid rgba(255,255,255,0.06);">
                    <h3 style="margin:0; font-size:0.85rem; color:{{ $col['tint'] }}; letter-spacing:0.04em;">{{ $col['label'] }}</h3>
                    <div style="font-size:0.6rem; color:#7a7a82;">
                        <strong style="color:#e5e5e7;">{{ $fmtNum($col['count']) }}</strong> kms ·
                        <strong style="color:#fde68a;">{{ $fmtIsk((float) $col['isk']) }}</strong>
                    </div>
                </div>

                {{-- Daily activity histogram --}}
                <div style="margin-bottom:0.65rem;">
                    <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:0.25rem;">Daily activity (kms)</div>
                    <div style="display:flex; align-items:flex-end; height:54px; gap:1px; background:rgba(0,0,0,0.30); padding:2px; border-radius:3px;">
                        @foreach ($r['daily'] as $d)
                            @php
                                $h = (int) round(((int) $d->kms / $maxDay) * 50);
                                $h = max(1, $h);
                                $iskFmt = $fmtIsk((float) $d->isk);
                            @endphp
                            <div title="{{ $d->day }} · {{ $fmtNum($d->kms) }} kms · {{ $iskFmt }}"
                                 style="flex:1; min-width:3px; height:{{ $h }}px; background:{{ $col['tint'] }}; opacity:0.85; border-radius:1px 1px 0 0;"></div>
                        @endforeach
                    </div>
                    @if (count($r['daily']) > 0)
                        <div style="display:flex; justify-content:space-between; font-size:0.5rem; color:#7a7a82; margin-top:0.2rem;">
                            <span>{{ $r['daily'][0]->day }}</span>
                            <span>{{ end($r['daily'])->day }}</span>
                        </div>
                    @endif
                </div>

                {{-- Hour-of-day histogram --}}
                <div style="margin-bottom:0.65rem;">
                    <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:0.25rem;">Hour of day (UTC)</div>
                    <div style="display:flex; align-items:flex-end; height:34px; gap:1px;">
                        @for ($hr = 0; $hr < 24; $hr++)
                            @php
                                $v = $hourMap[$hr] ?? 0;
                                $h = $v > 0 ? max(2, (int) round(($v / $maxHour) * 30)) : 1;
                            @endphp
                            <div title="{{ sprintf('%02d:00', $hr) }} · {{ $fmtNum($v) }} kms"
                                 style="flex:1; height:{{ $h }}px; background:{{ $col['tint'] }}; opacity:{{ $v > 0 ? '0.85' : '0.18' }}; border-radius:1px 1px 0 0;"></div>
                        @endfor
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:0.5rem; color:#7a7a82; margin-top:0.15rem;">
                        <span>00</span><span>06</span><span>12</span><span>18</span><span>23</span>
                    </div>
                </div>

                {{-- Ship-group breakdown --}}
                <div style="margin-bottom:0.65rem;">
                    <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:0.3rem;">Ship classes lost</div>
                    @foreach ($r['ship_groups'] as $g)
                        @php $w = max(2, (int) round(((int) $g->kms / $maxShip) * 100)); @endphp
                        <div style="display:flex; align-items:center; gap:0.4rem; font-size:0.62rem; margin-bottom:0.15rem;">
                            <div style="flex:0 0 92px; color:#cbd5e1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="{{ $g->label }}">{{ $g->label }}</div>
                            <div style="flex:1; height:11px; background:rgba(255,255,255,0.04); border-radius:2px; overflow:hidden;">
                                <div style="height:100%; width:{{ $w }}%; background:{{ $col['tint'] }}; opacity:0.7;"></div>
                            </div>
                            <div style="flex:0 0 38px; text-align:right; color:#e5e5e7; font-weight:600;">{{ $fmtNum($g->kms) }}</div>
                            <div style="flex:0 0 56px; text-align:right; color:#fde68a; font-size:0.58rem;">{{ $fmtIsk((float) $g->isk) }}</div>
                        </div>
                    @endforeach
                </div>

                {{-- Top victim alliances --}}
                @if (count($r['alliances']) > 0)
                    <div style="margin-bottom:0.65rem;">
                        <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:0.3rem;">Victim alliances</div>
                        @foreach ($r['alliances'] as $a)
                            @php $w = max(2, (int) round(((int) $a->kms / $maxAlly) * 100)); @endphp
                            <div style="display:flex; align-items:center; gap:0.4rem; font-size:0.62rem; margin-bottom:0.15rem;">
                                <div style="flex:0 0 110px; color:#cbd5e1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="{{ $a->label }}">{{ $a->label }}</div>
                                <div style="flex:1; height:11px; background:rgba(255,255,255,0.04); border-radius:2px; overflow:hidden;">
                                    <div style="height:100%; width:{{ $w }}%; background:{{ $col['tint'] }}; opacity:0.65;"></div>
                                </div>
                                <div style="flex:0 0 40px; text-align:right; color:#e5e5e7; font-weight:600;">{{ $fmtNum($a->kms) }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Top systems for this side --}}
                @if (count($r['systems']) > 0)
                    <div style="margin-bottom:0.65rem;">
                        <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:0.3rem;">Top systems (where they died)</div>
                        @foreach ($r['systems'] as $s)
                            @php
                                $w = max(2, (int) round(((int) $s->kms / $maxSys) * 100));
                                $sysColor = $sevColor($s->security_status ?? null);
                            @endphp
                            <div style="display:flex; align-items:center; gap:0.4rem; font-size:0.62rem; margin-bottom:0.15rem;">
                                <div style="flex:0 0 70px; color:{{ $sysColor }}; font-weight:600;">{{ $s->label }}</div>
                                <div style="flex:1; height:11px; background:rgba(255,255,255,0.04); border-radius:2px; overflow:hidden;">
                                    <div style="height:100%; width:{{ $w }}%; background:{{ $col['tint'] }}; opacity:0.55;"></div>
                                </div>
                                <div style="flex:0 0 40px; text-align:right; color:#e5e5e7; font-weight:600;">{{ $fmtNum($s->kms) }}</div>
                                <div style="flex:0 0 56px; text-align:right; color:#fde68a; font-size:0.58rem;">{{ $fmtIsk((float) $s->isk) }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Recent feed (last 15) --}}
                @if (count($recentRows) > 0)
                    <details style="margin-top:0.4rem;">
                        <summary style="cursor:pointer; padding:0.3rem 0.4rem; background:rgba(255,255,255,0.03); border-radius:4px; font-size:0.6rem; color:#cbd5e1; list-style:none; text-transform:uppercase; letter-spacing:0.06em;">Recent {{ count($recentRows) }} losses</summary>
                        <div style="padding-top:0.3rem;">
                            @foreach ($recentRows as $rr)
                                @php
                                    $isPod = in_array((int) $rr->victim_ship_type_id, [670, 33328], true);
                                    $isCleanPod = $isPod && (float) $rr->total_value <= 0.0;
                                @endphp
                                <div style="padding:0.25rem 0.35rem; border-bottom:1px solid rgba(255,255,255,0.04); font-size:0.6rem; line-height:1.3;">
                                    <div style="display:flex; gap:0.4rem; align-items:baseline; flex-wrap:wrap;">
                                        <span style="color:#7a7a82;">{{ \Carbon\Carbon::parse($rr->killed_at)->format('M d H:i') }}</span>
                                        @if ($isCleanPod)
                                            <a href="https://zkillboard.com/kill/{{ $rr->killmail_id }}/" target="_blank" rel="noopener"
                                               title="Clean clone — no implants destroyed."
                                               style="color:#7a7a82; text-decoration:none; font-style:italic;">clean clone</a>
                                        @else
                                            <a href="https://zkillboard.com/kill/{{ $rr->killmail_id }}/" target="_blank" rel="noopener"
                                               style="color:#fde68a; text-decoration:none; font-weight:600;">{{ $fmtIsk((float) $rr->total_value) }}</a>
                                        @endif
                                        <span style="color:#7dd3fc;">{{ $rr->system_name }}</span>
                                        <span style="color:#cbd5e1; flex:1;">{{ $rr->victim_ship_type_name ?: ($isPod ? 'Capsule' : '—') }}</span>
                                    </div>
                                    <div style="color:#9ca3af; font-size:0.55rem; margin-top:0.05rem;">
                                        {{ $rr->victim_name ?: 'unknown pilot' }}
                                        <span style="color:#7a7a82;"> · {{ $rr->victim_alliance_name ?: 'no alliance' }}</span>
                                        @if ($rr->fb_char_name)
                                            <span style="color:#7a7a82;"> · fb {{ $rr->fb_char_name }}</span>
                                            @if ($rr->fb_alliance_name)
                                                <span style="color:#7a7a82;">({{ $rr->fb_alliance_name }})</span>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
            </div>
        @endforeach
    </div>


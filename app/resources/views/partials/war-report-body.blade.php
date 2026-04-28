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
        // EVE imagery via local proxy (storage/app/eve-images cache).
        // Each helper returns null for missing/zero ids so the blade
        // can `@if ($icon)` to skip rendering. size=64 covers 16-32px
        // display sizes at 2× DPR cleanly; bump per call when needed.
        $shipIcon = fn (?int $id, int $size = 64) => $id ? "/img/type/{$id}?size={$size}" : null;
        $charIcon = fn (?int $id, int $size = 64) => ($id !== null && $id > 0) ? "/img/character/{$id}?size={$size}" : null;
        $allianceIcon = fn (?int $id, int $size = 64) => ($id !== null && $id > 0) ? "/img/alliance/{$id}?size={$size}" : null;
    @endphp
    <style>
        .aegis-icon {
            display:inline-block; vertical-align:middle;
            border-radius:2px;
            background: rgba(255,255,255,0.04);
        }
        .aegis-icon-ship    { width:16px; height:16px; }
        .aegis-icon-ship-md { width:24px; height:24px; }
        .aegis-icon-char    { width:16px; height:16px; border-radius:50%; }
        .aegis-icon-char-md { width:28px; height:28px; border-radius:50%; }
        .aegis-icon-ally    { width:14px; height:14px; }
        .aegis-icon-ally-md { width:22px; height:22px; }
    </style>
    @php
        $tiles = [
            'wc' => ['label' => 'WinterCo losses', 'tint' => '#86efac', 'count' => $totals['wc']['kms'], 'isk' => $totals['wc']['isk']],
            'op' => ['label' => $opposing_label . ' losses', 'tint' => $opposing_tint, 'count' => $totals['op']['kms'], 'isk' => $totals['op']['isk']],
        ];
        $sideKeys = ['wc', 'op'];
        $totalKms = $totals['wc']['kms'] + $totals['op']['kms'];
        $totalIsk = $totals['wc']['isk'] + $totals['op']['isk'];
    @endphp

    {{-- Hero banner --}}
    <div style="position:relative; padding:1.5rem 1.75rem; margin-bottom:1rem; border-radius:10px;
                background:linear-gradient(135deg, rgba(34,197,94,0.08) 0%, rgba(0,0,0,0.4) 50%, rgba(239,68,68,0.10) 100%);
                border:1px solid rgba(255,255,255,0.10); overflow:hidden;">
        <div style="display:flex; gap:2rem; align-items:flex-start; flex-wrap:wrap;">
            <div style="flex:2; min-width:280px;">
                <div style="font-size:0.6rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.12em; margin-bottom:0.4rem;">Active conflict</div>
                @php
                    // Display label is computed per-render (not cached)
                    // so the side-order swaps each visit. parse the
                    // already-formatted "{A} vs {B}" string into spans
                    // tinted by which side is which.
                    $label = $display_label ?? ('WinterCo vs ' . $opposing_label);
                    [$leftRaw, $rightRaw] = array_pad(array_map('trim', explode(' vs ', $label, 2)), 2, '');
                    $colorOf = fn (string $name): string => $name === 'WinterCo' ? '#86efac' : $opposing_tint;
                @endphp
                <h1 style="margin:0 0 0.4rem 0; font-size:1.5rem; color:#e5e5e7; font-weight:700; letter-spacing:0.02em;">
                    <span style="color:{{ $colorOf($leftRaw) }};">{{ $leftRaw }}</span>
                    <span style="color:#7a7a82; font-weight:400;"> vs </span>
                    <span style="color:{{ $colorOf($rightRaw) }};">{{ $rightRaw }}</span>
                </h1>
                <p style="margin:0; font-size:0.78rem; color:#9ca3af;">
                    Conflict floor <span style="color:#cbd5e1;">{{ \Carbon\Carbon::parse($war_start)->format('Y-m-d') }}</span> ·
                    <span style="color:#cbd5e1;">{{ $total_days }}</span> days running ·
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

    {{-- Live-battle banner — battles with a killmail in the last 90
         min OR end_time still NULL. Click to open the battle report
         (system map + side breakdown + live kill feed). --}}
    @php $live = $live_battles ?? []; @endphp
    @php $olderBattlesUrl = isset($conflict_key) ? '/battles/' . $conflict_key : '/battles'; @endphp
    @if (count($live) > 0)
        <div style="margin-bottom:0.75rem; padding:0.55rem 0.85rem; border:1px solid rgba(134,239,172,0.35); border-radius:8px; background:linear-gradient(90deg, rgba(134,239,172,0.10) 0%, rgba(0,0,0,0.45) 100%);">
            <div style="display:flex; align-items:center; gap:0.6rem; margin-bottom:0.35rem;">
                <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#86efac; box-shadow:0 0 8px #86efac; animation:aegis-live-pulse 1.4s ease-in-out infinite;"></span>
                <strong style="font-size:0.7rem; color:#86efac; letter-spacing:0.08em; text-transform:uppercase;">Live now</strong>
                <span style="font-size:0.6rem; color:#7a7a82;">· {{ count($live) }} battle{{ count($live) === 1 ? '' : 's' }} active · click to open report</span>
                <a href="{{ $olderBattlesUrl }}" style="margin-left:auto; font-size:0.6rem; color:#cbd5e1; text-decoration:none; padding:0.15rem 0.5rem; border:1px solid rgba(255,255,255,0.10); border-radius:4px; background:rgba(255,255,255,0.04);">Older battles →</a>
            </div>
            <div style="display:flex; gap:0.4rem; flex-wrap:wrap;">
                @foreach ($live as $b)
                    @php
                        $secColor = $sevColor((float) ($b->security_status ?? null));
                        $slugOrId = $b->public_slug ?: (string) $b->id;
                    @endphp
                    <a href="/battles/{{ $slugOrId }}" style="display:inline-flex; align-items:center; gap:0.4rem; padding:0.3rem 0.6rem; border:1px solid rgba(134,239,172,0.30); border-radius:5px; background:rgba(0,0,0,0.30); text-decoration:none;">
                        <span style="font-size:0.75rem; font-weight:700; color:{{ $secColor }};">{{ $b->system_name }}</span>
                        <span style="font-size:0.6rem; color:#cbd5e1;">{{ $fmtNum($b->total_kills ?: 0) }} kms</span>
                        <span style="font-size:0.6rem; color:#fde68a;">{{ $fmtIsk((float) ($b->total_isk_lost ?: 0)) }}</span>
                        <span style="font-size:0.55rem; color:#7a7a82;">last {{ \Carbon\Carbon::parse($b->newest_km)->diffForHumans() }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @else
        <div style="margin-bottom:0.75rem; padding:0.45rem 0.85rem; border:1px solid rgba(255,255,255,0.06); border-radius:8px; background:rgba(0,0,0,0.20); display:flex; align-items:center; gap:0.6rem;">
            <span style="font-size:0.6rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">No live battles right now</span>
            <a href="{{ $olderBattlesUrl }}" style="margin-left:auto; font-size:0.6rem; color:#cbd5e1; text-decoration:none; padding:0.15rem 0.5rem; border:1px solid rgba(255,255,255,0.10); border-radius:4px; background:rgba(255,255,255,0.04);">Older battles →</a>
        </div>
    @endif
    <style>
        @keyframes aegis-live-pulse {
            0%   { opacity: 1;   transform: scale(1); }
            50%  { opacity: 0.4; transform: scale(0.85); }
            100% { opacity: 1;   transform: scale(1); }
        }
    </style>

    {{-- Hot-kills ticker is rendered at the bottom of the file as a
         viewport-fixed bar; see the .aegis-ticker-fixed block below.
         A spacer leaves room for the bar so the last section isn't
         hidden behind it. --}}


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
                        $sideTint = $m->side === 'wc' ? '#86efac' : ($m->side === 'hostile' ? $opposing_tint : '#9ca3af');
                        $sideLbl = $m->side === 'wc' ? 'WinterCo' : ($m->side === 'hostile' ? $opposing_label : '—');
                    @endphp
                    @php
                        $shipUrl = $shipIcon((int) ($m->victim_ship_type_id ?? 0), 64);
                        $allyUrl = $allianceIcon((int) ($m->victim_alliance_id ?? 0), 64);
                    @endphp
                    <div style="position:relative; padding:0.5rem 0.7rem; border:1px solid rgba(255,255,255,0.06); border-radius:5px; background:rgba(0,0,0,0.20);">
                        <a href="/kills/{{ $m->killmail_id }}" style="display:flex; gap:0.5rem; text-decoration:none; color:inherit;">
                            @if ($shipUrl)
                                <img src="{{ $shipUrl }}" loading="lazy" referrerpolicy="no-referrer" alt=""
                                     class="aegis-icon aegis-icon-ship-md" style="width:32px; height:32px; flex:0 0 32px; align-self:center;">
                            @endif
                            <div style="flex:1; min-width:0;">
                                <div style="display:flex; align-items:baseline; gap:0.4rem; flex-wrap:wrap;">
                                    <span style="font-size:0.55rem; color:#7a7a82; min-width:14px;">#{{ $i + 1 }}</span>
                                    <span style="font-size:1rem; font-weight:700; color:#fde68a;">{{ $fmtIsk((float) $m->total_value) }}</span>
                                    <span style="font-size:0.55rem; color:{{ $sideTint }}; text-transform:uppercase; letter-spacing:0.06em;">{{ $sideLbl }}</span>
                                </div>
                                <div style="font-size:0.7rem; color:#cbd5e1; margin-top:0.15rem;">{{ $m->victim_ship_type_name ?: 'Unknown' }} <span style="color:#7a7a82;">· {{ $m->victim_name ?: '—' }}</span></div>
                                <div style="font-size:0.6rem; color:#7a7a82; margin-top:0.1rem; display:flex; align-items:center; gap:0.25rem;">
                                    @if ($allyUrl)
                                        <img src="{{ $allyUrl }}" loading="lazy" referrerpolicy="no-referrer" alt="" class="aegis-icon aegis-icon-ally">
                                    @endif
                                    <span>{{ $m->victim_alliance_name ?: '—' }}</span>
                                    <span>· {{ $m->system_name }} · {{ \Carbon\Carbon::parse($m->killed_at)->format('M d H:i') }}</span>
                                </div>
                            </div>
                        </a>
                        <a href="https://zkillboard.com/kill/{{ $m->killmail_id }}/" target="_blank" rel="noopener"
                           title="Open on zKillboard"
                           style="position:absolute; top:0.4rem; right:0.5rem; font-size:0.55rem; color:#7a7a82; text-decoration:none;">zkill ↗</a>
                    </div>
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
                        @php
                            $w = max(2, (int) round(((int) $row->{$b['metric']} / $maxMetric) * 100));
                            $isAlliance = str_contains((string) $b['title'], 'alliances');
                            $iconUrl = $isAlliance
                                ? $allianceIcon((int) ($row->id ?? 0), 64)
                                : $charIcon((int) ($row->id ?? 0), 64);
                            $allyIconUrl = $allianceIcon((int) ($row->alliance_id ?? 0), 64);
                        @endphp
                        <div style="display:flex; align-items:center; gap:0.4rem; font-size:0.62rem; padding:0.18rem 0; border-bottom:1px solid rgba(255,255,255,0.04);">
                            <span style="flex:0 0 14px; color:#7a7a82; font-size:0.55rem;">{{ $i + 1 }}</span>
                            @if ($iconUrl)
                                <img src="{{ $iconUrl }}" loading="lazy" referrerpolicy="no-referrer" alt=""
                                     class="aegis-icon {{ $isAlliance ? 'aegis-icon-ally-md' : 'aegis-icon-char-md' }}">
                            @endif
                            <div style="flex:1; min-width:0;">
                                <div style="color:#e5e5e7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="{{ $row->name }}">{{ $row->name }}</div>
                                @if (isset($row->alliance_name))
                                    <div style="color:#7a7a82; font-size:0.55rem; display:flex; align-items:center; gap:0.25rem;">
                                        @if (! $isAlliance && $allyIconUrl)
                                            <img src="{{ $allyIconUrl }}" loading="lazy" referrerpolicy="no-referrer" alt=""
                                                 class="aegis-icon aegis-icon-ally">
                                        @endif
                                        <span>{{ $row->alliance_name }}</span>
                                    </div>
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
                        $sideTint = $p->side === 'wc' ? '#86efac' : ($p->side === 'hostile' ? $opposing_tint : '#9ca3af');
                    @endphp
                    <div style="position:relative; padding:0.5rem 0.7rem; border:1px solid rgba(255,255,255,0.06); border-radius:5px; background:rgba(0,0,0,0.20);">
                        <a href="/kills/{{ $p->killmail_id }}" style="display:block; text-decoration:none; color:inherit;">
                            <div style="display:flex; align-items:baseline; gap:0.5rem;">
                                <span style="font-size:0.95rem; font-weight:700; color:#fde68a;">{{ $fmtIsk((float) $p->total_value) }}</span>
                                <span style="font-size:0.55rem; color:{{ $sideTint }}; text-transform:uppercase; letter-spacing:0.06em;">{{ $p->side === 'wc' ? 'WinterCo' : ($p->side === 'hostile' ? $opposing_label : '—') }}</span>
                            </div>
                            <div style="font-size:0.65rem; color:#cbd5e1; margin-top:0.15rem;">{{ $p->victim_name ?: 'unknown pilot' }} <span style="color:#7a7a82;">· {{ $p->victim_alliance_name ?: '—' }}</span></div>
                            <div style="font-size:0.6rem; color:#7a7a82; margin-top:0.1rem;">{{ $p->system_name }} · {{ \Carbon\Carbon::parse($p->killed_at)->format('M d H:i') }}</div>
                        </a>
                        <a href="https://zkillboard.com/kill/{{ $p->killmail_id }}/" target="_blank" rel="noopener"
                           title="Open on zKillboard"
                           style="position:absolute; top:0.4rem; right:0.5rem; font-size:0.55rem; color:#7a7a82; text-decoration:none;">zkill ↗</a>
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
                                $sideColor = $s->side === 'wc' ? '#86efac' : ($s->side === 'hostile' ? $opposing_tint : '#9ca3af');
                                $sideLbl = $s->side === 'wc' ? 'WinterCo' : ($s->side === 'hostile' ? $opposing_label : '—');
                            @endphp
                            <tr style="border-top:1px solid rgba(255,255,255,0.04);">
                                <td style="padding:0.35rem 0.6rem; color:#cbd5e1; white-space:nowrap;">{{ \Carbon\Carbon::parse($s->killed_at)->format('M d H:i') }}</td>
                                <td style="padding:0.35rem 0.6rem; color:#7dd3fc;">{{ $s->system_name }}</td>
                                <td style="padding:0.35rem 0.6rem; color:{{ $sideColor }}; font-size:0.6rem; text-transform:uppercase; letter-spacing:0.06em;">{{ $sideLbl }}</td>
                                <td style="padding:0.35rem 0.6rem; color:#fde68a;">{{ $s->victim_ship_type_name ?: $s->victim_ship_group_name ?: 'Structure' }}</td>
                                <td style="padding:0.35rem 0.6rem; color:#cbd5e1;">{{ $s->victim_alliance_name ?: $s->victim_corp_name ?: '—' }}</td>
                                <td style="padding:0.35rem 0.6rem; text-align:right; color:#fde68a; white-space:nowrap;">
                                    <a href="/kills/{{ $s->killmail_id }}" style="color:inherit; text-decoration:none; font-weight:600;">{{ $fmtIsk((float) $s->total_value) }}</a>
                                    <a href="https://zkillboard.com/kill/{{ $s->killmail_id }}/" target="_blank" rel="noopener" title="Open on zKillboard" style="margin-left:0.35rem; font-size:0.5rem; color:#7a7a82; text-decoration:none;">↗</a>
                                </td>
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
    <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:0.75rem;">
        @foreach ($sideKeys as $key)
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

                {{-- Ship-group breakdown — caps/supers/titans pinned
                     to the top via priority field on the SQL row
                     (ORDER BY priority ASC, kms DESC). Strategic
                     classes always visible, the long subcap tail
                     scrolls inside a fixed-height container. --}}
                <div style="margin-bottom:0.65rem;">
                    <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:0.3rem;">Ship classes lost</div>
                    <div style="max-height:340px; overflow-y:auto; padding-right:0.25rem;">
                        @foreach ($r['ship_groups'] as $g)
                            @php
                                $w = max(2, (int) round(((int) $g->kms / $maxShip) * 100));
                                $prio = (int) ($g->priority ?? 4);
                                $isPinned = $prio <= 3;
                            @endphp
                            <div style="display:flex; align-items:center; gap:0.4rem; font-size:0.62rem; margin-bottom:0.15rem;{{ $isPinned ? ' background:rgba(253,224,71,0.05); border-left:2px solid #fde68a; padding:1px 0 1px 4px;' : '' }}">
                                <div style="flex:0 0 92px; color:{{ $isPinned ? '#fde68a' : '#cbd5e1' }}; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="{{ $g->label }}">{{ $g->label }}</div>
                                <div style="flex:1; height:11px; background:rgba(255,255,255,0.04); border-radius:2px; overflow:hidden;">
                                    <div style="height:100%; width:{{ $w }}%; background:{{ $col['tint'] }}; opacity:0.7;"></div>
                                </div>
                                <div style="flex:0 0 38px; text-align:right; color:#e5e5e7; font-weight:600;">{{ $fmtNum($g->kms) }}</div>
                                <div style="flex:0 0 56px; text-align:right; color:#fde68a; font-size:0.58rem;">{{ $fmtIsk((float) $g->isk) }}</div>
                            </div>
                        @endforeach
                    </div>
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

                {{-- Recent feed (last 15) --}}
                @if (count($recentRows) > 0)
                    <details open style="margin-top:0.4rem;">
                        <summary style="cursor:pointer; padding:0.3rem 0.4rem; background:rgba(255,255,255,0.03); border-radius:4px; font-size:0.6rem; color:#cbd5e1; list-style:none; text-transform:uppercase; letter-spacing:0.06em;">Recent {{ count($recentRows) }} losses</summary>
                        <div style="padding-top:0.3rem;">
                            @foreach ($recentRows as $rr)
                                @php
                                    $isPod = in_array((int) $rr->victim_ship_type_id, [670, 33328], true);
                                    $isCleanPod = $isPod && (float) $rr->total_value <= 0.0;
                                    $rrShip = $shipIcon((int) ($rr->victim_ship_type_id ?? 0), 64);
                                    $rrChar = $charIcon((int) ($rr->victim_character_id ?? 0), 64);
                                    $rrAlly = $allianceIcon((int) ($rr->victim_alliance_id ?? 0), 64);
                                    $fbAlly = $allianceIcon((int) ($rr->fb_alliance_id ?? 0), 64);
                                @endphp
                                <div style="padding:0.25rem 0.35rem; border-bottom:1px solid rgba(255,255,255,0.04); font-size:0.6rem; line-height:1.3;">
                                    <div style="display:flex; gap:0.4rem; align-items:baseline; flex-wrap:wrap;">
                                        <span style="color:#7a7a82;">{{ \Carbon\Carbon::parse($rr->killed_at)->format('M d H:i') }}</span>
                                        @if ($rrShip)<img src="{{ $rrShip }}" loading="lazy" referrerpolicy="no-referrer" alt="" class="aegis-icon aegis-icon-ship">@endif
                                        @if ($isCleanPod)
                                            <a href="/kills/{{ $rr->killmail_id }}"
                                               title="Clean clone — no implants destroyed."
                                               style="color:#7a7a82; text-decoration:none; font-style:italic;">clean clone</a>
                                        @else
                                            <a href="/kills/{{ $rr->killmail_id }}"
                                               style="color:#fde68a; text-decoration:none; font-weight:600;">{{ $fmtIsk((float) $rr->total_value) }}</a>
                                        @endif
                                        <a href="https://zkillboard.com/kill/{{ $rr->killmail_id }}/" target="_blank" rel="noopener" title="Open on zKillboard" style="font-size:0.5rem; color:#7a7a82; text-decoration:none;">↗</a>
                                        <span style="color:#7dd3fc;">{{ $rr->system_name }}</span>
                                        <span style="color:#cbd5e1; flex:1;">{{ $rr->victim_ship_type_name ?: ($isPod ? 'Capsule' : '—') }}</span>
                                    </div>
                                    <div style="color:#9ca3af; font-size:0.55rem; margin-top:0.05rem; display:flex; align-items:center; gap:0.25rem; flex-wrap:wrap;">
                                        @if ($rrChar)<img src="{{ $rrChar }}" loading="lazy" referrerpolicy="no-referrer" alt="" class="aegis-icon aegis-icon-char">@endif
                                        <span>{{ $rr->victim_name ?: 'unknown pilot' }}</span>
                                        <span style="color:#7a7a82;">·</span>
                                        @if ($rrAlly)<img src="{{ $rrAlly }}" loading="lazy" referrerpolicy="no-referrer" alt="" class="aegis-icon aegis-icon-ally">@endif
                                        <span style="color:#7a7a82;">{{ $rr->victim_alliance_name ?: 'no alliance' }}</span>
                                        @if ($rr->fb_char_name)
                                            <span style="color:#7a7a82;">· fb {{ $rr->fb_char_name }}</span>
                                            @if ($rr->fb_alliance_name)
                                                @if ($fbAlly)<img src="{{ $fbAlly }}" loading="lazy" referrerpolicy="no-referrer" alt="" class="aegis-icon aegis-icon-ally">@endif
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

    {{-- Top systems (where each side died) — full-width footer
         section. Per-side block was misaligning the per-side panel
         heights, so rendered here as a single row mirroring the
         upwell-structure timeline style. --}}
    <div style="margin-top:1rem; padding:0.85rem 1rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(255,255,255,0.02);">
        <div style="display:flex; align-items:baseline; gap:0.6rem; margin-bottom:0.6rem; flex-wrap:wrap;">
            <h2 style="margin:0; font-size:0.85rem; color:#e5e5e7;">Top systems by side losses</h2>
            <span style="font-size:0.6rem; color:#7a7a82;">where each side died · entire conflict</span>
        </div>
        <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:0.6rem;">
            @foreach ($sideKeys as $key)
                @php
                    $col = $tiles[$key];
                    $r = $rollups[$key] ?? ['systems' => []];
                    $sysRows = $r['systems'] ?? [];
                    $maxSys = $maxOf($sysRows, 'kms');
                @endphp
                <div style="padding:0.55rem 0.7rem; border:1px solid rgba(255,255,255,0.06); border-radius:5px; background:rgba(0,0,0,0.20);">
                    <div style="font-size:0.6rem; color:{{ $col['tint'] }}; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:0.35rem;">{{ $col['label'] }}</div>
                    @if (count($sysRows) === 0)
                        <p style="font-size:0.65rem; color:#9ca3af; font-style:italic;">No data.</p>
                    @else
                        @foreach ($sysRows as $s)
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
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Fixed-bottom hot-kills ticker — always visible while scrolling.
         Spacer above keeps the last section from sliding under it. --}}
    @php $ticker = $ticker_kills ?? []; @endphp
    @if (count($ticker) > 0)
        <div style="height:54px;"></div>{{-- spacer matching the fixed ticker height --}}
        <div class="aegis-ticker-fixed">
            <div class="aegis-ticker-fixed-label">
                <span style="color:#fde68a;">⚡</span>
                <span>Hot kills · last 24h</span>
            </div>
            <div class="aegis-ticker">
                <div class="aegis-ticker-track">
                    @foreach (array_merge($ticker, $ticker) as $t)
                        @php
                            $tShip = $shipIcon((int) ($t->victim_ship_type_id ?? 0), 64);
                            $tAlly = $allianceIcon((int) ($t->victim_alliance_id ?? 0), 64);
                        @endphp
                        <a href="/kills/{{ $t->killmail_id }}" class="aegis-ticker-item">
                            @if ($tShip)<img src="{{ $tShip }}" loading="lazy" referrerpolicy="no-referrer" alt="" class="aegis-icon aegis-icon-ship">@endif
                            <span style="color:#fde68a; font-weight:700;">{{ $fmtIsk((float) $t->total_value) }}</span>
                            <span style="color:#cbd5e1;">{{ $t->victim_ship_type_name ?: '?' }}</span>
                            <span style="color:#7dd3fc;">{{ $t->system_name }}</span>
                            <span style="color:#9ca3af; font-size:0.55rem;">{{ $t->victim_name ?: '—' }}</span>
                            @if ($tAlly)<img src="{{ $tAlly }}" loading="lazy" referrerpolicy="no-referrer" alt="" class="aegis-icon aegis-icon-ally">@endif
                            <span style="color:#7a7a82; font-size:0.55rem;">{{ $t->victim_alliance_name ?: '—' }}</span>
                            <span style="color:#7a7a82; font-size:0.5rem;">{{ \Carbon\Carbon::parse($t->killed_at)->format('H:i') }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
        <style>
            .aegis-ticker-fixed {
                position: fixed;
                bottom: 0; left: 0; right: 0;
                z-index: 90;
                display: flex;
                align-items: center;
                gap: 0.6rem;
                padding: 0.45rem 0.75rem;
                background: rgba(5, 7, 9, 0.92);
                border-top: 1px solid rgba(253, 224, 71, 0.25);
                box-shadow: 0 -4px 18px rgba(0, 0, 0, 0.55);
                backdrop-filter: blur(6px);
                -webkit-backdrop-filter: blur(6px);
            }
            .aegis-ticker-fixed-label {
                flex: 0 0 auto;
                font-size: 0.55rem;
                color: #7a7a82;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                white-space: nowrap;
                padding-right: 0.6rem;
                border-right: 1px solid rgba(255,255,255,0.08);
            }
            .aegis-ticker { flex: 1 1 auto; min-width: 0; overflow: hidden; }
            .aegis-ticker-track {
                display: flex;
                gap: 1.6rem;
                animation: aegis-ticker-scroll 90s linear infinite;
                will-change: transform;
            }
            .aegis-ticker:hover .aegis-ticker-track { animation-play-state: paused; }
            .aegis-ticker-item {
                display: inline-flex;
                align-items: baseline;
                gap: 0.4rem;
                padding: 0.15rem 0.5rem;
                font-size: 0.7rem;
                white-space: nowrap;
                text-decoration: none;
                color: inherit;
                border-left: 2px solid rgba(253, 224, 71, 0.20);
            }
            .aegis-ticker-item:hover { background: rgba(253, 224, 71, 0.06); }
            @keyframes aegis-ticker-scroll {
                from { transform: translateX(0); }
                to   { transform: translateX(-50%); }
            }
        </style>
    @endif


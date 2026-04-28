<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your War Effort — {{ $display_label }}</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Ctext y='13' font-size='14'%3E%E2%9A%94%3C/text%3E%3C/svg%3E">
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Inter, system-ui, sans-serif;
            background: #050709;
            color: #e5e5e7;
            font-size: 0.85rem;
            line-height: 1.45;
        }
        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 1.5rem 1.25rem 4rem;
            position: relative;
            z-index: 1;
        }
        .public-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.5rem 0 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }
        .public-header h1 {
            margin: 0;
            font-size: 1rem;
            color: #e5e5e7;
            letter-spacing: 0.04em;
            font-weight: 600;
        }
        a { color: inherit; }
        .sso-cta {
            display:inline-flex; align-items:center; gap:0.5rem;
            padding:0.7rem 1.2rem;
            border-radius:8px;
            text-decoration:none;
            background:linear-gradient(135deg, rgba(79,208,208,0.15) 0%, rgba(0,0,0,0.5) 60%, {{ $opposing_tint }} 100%);
            border:1px solid rgba(79,208,208,0.40);
            color:#e5e5e7;
            font-weight:600; letter-spacing:0.04em;
            box-shadow:0 0 18px rgba(79,208,208,0.25);
            transition: box-shadow 0.2s, transform 0.15s;
        }
        .sso-cta:hover { box-shadow:0 0 28px rgba(79,208,208,0.50); transform:translateY(-1px); }
        .stat-card {
            padding:0.85rem 1rem;
            border:1px solid rgba(255,255,255,0.08);
            border-radius:8px;
            background:rgba(0,0,0,0.30);
        }
        .badge-card {
            position:relative;
            padding:0.85rem 1rem;
            border:1px solid rgba(255,255,255,0.10);
            border-radius:10px;
            background:rgba(0,0,0,0.30);
            overflow:hidden;
        }
        .badge-tier-strip {
            position:absolute; top:0; left:0; bottom:0;
            width:4px;
        }
        .tier-1 { background:linear-gradient(180deg, #fde68a 0%, #f59e0b 100%); }
        .tier-2 { background:linear-gradient(180deg, #c4b5fd 0%, #8b5cf6 100%); }
        .tier-3 { background:linear-gradient(180deg, #93c5fd 0%, #2563eb 100%); }
        .tier-4 { background:linear-gradient(180deg, #86efac 0%, #16a34a 100%); }
        .tier-5 { background:linear-gradient(180deg, #67e8f9 0%, #0891b2 100%); }
        .tier-6 { background:linear-gradient(180deg, #fdba74 0%, #c2410c 100%); }
        .tier-7 { background:linear-gradient(180deg, #fca5a5 0%, #b91c1c 100%); }
        .tier-8 { background:linear-gradient(180deg, #d4d4d8 0%, #71717a 100%); }
        .tier-9 { background:linear-gradient(180deg, #a3a3a3 0%, #525252 100%); }
        .tier-10 { background:linear-gradient(180deg, #71717a 0%, #404040 100%); }
        /* Pure-CSS tabs (radio + labels). All radios are siblings of
           the nav and the section panes so :checked ~ works. */
        .aegis-tabs > input[type=radio] { display:none; }
        .aegis-tab-nav {
            display:flex; gap:0.3rem;
            border-bottom:1px solid rgba(255,255,255,0.10);
            margin-bottom:1.25rem;
            flex-wrap:wrap;
        }
        .aegis-tab-nav label {
            padding:0.55rem 1rem;
            cursor:pointer;
            font-size:0.7rem; letter-spacing:0.06em;
            text-transform:uppercase;
            color:#9ca3af;
            border-bottom:2px solid transparent;
            margin-bottom:-1px;
            user-select:none;
            transition:color 0.12s, border-color 0.12s;
        }
        .aegis-tab-nav label:hover { color:#e5e5e7; }
        .aegis-tabs > section[data-tab] { display:none; }
        .aegis-tabs > #atab-overview:checked  ~ section[data-tab="atab-overview"]  { display:block; }
        .aegis-tabs > #atab-combat:checked    ~ section[data-tab="atab-combat"]    { display:block; }
        .aegis-tabs > #atab-killboard:checked ~ section[data-tab="atab-killboard"] { display:block; }
        .aegis-tabs > #atab-social:checked    ~ section[data-tab="atab-social"]    { display:block; }
        .aegis-tabs > #atab-map:checked       ~ section[data-tab="atab-map"]       { display:block; }
        .aegis-tabs > #atab-overview:checked  ~ .aegis-tab-nav label[for="atab-overview"],
        .aegis-tabs > #atab-combat:checked    ~ .aegis-tab-nav label[for="atab-combat"],
        .aegis-tabs > #atab-killboard:checked ~ .aegis-tab-nav label[for="atab-killboard"],
        .aegis-tabs > #atab-social:checked    ~ .aegis-tab-nav label[for="atab-social"],
        .aegis-tabs > #atab-map:checked       ~ .aegis-tab-nav label[for="atab-map"] {
            color:#e5e5e7; border-bottom-color:#fde68a;
        }
    </style>
    @include('partials.aegis-public-bg')
</head>
<body class="aegis-public-bg" data-page="{{ $page_class }}">
    <div class="container">
        <div class="public-header">
            <h1>⚔ {{ $display_label }} — Your effort</h1>
            <span style="font-size:0.65rem; color:#7a7a82; letter-spacing:0.04em; text-transform:uppercase;">
                <a href="/war-report/{{ $conflict }}" style="color:#7dd3fc; text-decoration:none;">← back to report</a>
            </span>
        </div>

        @php
            $fmtIsk = function (float $v): string {
                if ($v >= 1e12) return number_format($v / 1e12, 2) . ' T';
                if ($v >= 1e9)  return number_format($v / 1e9, 2) . ' B';
                if ($v >= 1e6)  return number_format($v / 1e6, 2) . ' M';
                return number_format($v, 0);
            };
            $fmtNum = fn ($n) => number_format((int) $n);
        @endphp

        @if (! $signed_in)
            <div style="padding:2rem; border:1px solid rgba(79,208,208,0.20); border-radius:10px; background:rgba(0,0,0,0.30); text-align:center;">
                <h2 style="margin:0 0 0.6rem 0; font-size:1.3rem; color:#e5e5e7;">See your effort in this war</h2>
                <p style="margin:0 0 1.2rem 0; font-size:0.85rem; color:#cbd5e1;">
                    Sign in via EVE Online SSO. We'll show your kills, ISK destroyed, battles attended, and the badge tier you've earned vs every other pilot in this conflict.
                </p>
                <a class="sso-cta" href="/auth/eve/war-stats?conflict={{ $conflict }}">
                    <span>🔓</span>
                    <span>Sign in with EVE</span>
                </a>
                <p style="margin:1rem 0 0 0; font-size:0.6rem; color:#7a7a82;">
                    Scopes requested: <code style="color:#fde68a;">publicData</code> + <code style="color:#fde68a;">esi-killmails.read_killmails.v1</code>.
                    Separate session from any other login. Character identity is read once, no tokens stored, no account created.
                </p>
            </div>
        @else
            @php
                $charPortrait = '/img/character/' . $character_id . '?size=128';
                $totalKills = (int) $stats['kills'];
                $totalLosses = (int) $stats['losses'];
                $efficiencyIsk = $stats['isk_destroyed'] + $stats['isk_lost'] > 0
                    ? round(($stats['isk_destroyed'] / ($stats['isk_destroyed'] + $stats['isk_lost'])) * 100, 1)
                    : 0;
            @endphp
            <div style="display:flex; gap:1rem; align-items:center; padding:1rem; border:1px solid rgba(255,255,255,0.10); border-radius:10px; background:rgba(0,0,0,0.30); margin-bottom:1.25rem;">
                <img src="{{ $charPortrait }}" alt="" style="width:72px; height:72px; border-radius:50%;">
                <div style="flex:1;">
                    <div style="font-size:0.6rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">Signed in as</div>
                    <div style="display:flex; align-items:baseline; gap:0.6rem; flex-wrap:wrap;">
                        <div style="font-size:1.15rem; font-weight:700; color:#e5e5e7;">{{ $character_name }}</div>
                        @if (! empty($overall_badge))
                            @php
                                $ob = $overall_badge;
                                $badgeColor = $ob['bucket'] <= 10 ? '#fde68a' : ($ob['bucket'] <= 20 ? '#cbd5e1' : '#fca5a5');
                            @endphp
                            <span title="bucket {{ $ob['bucket'] }}/30 · avg tier {{ $ob['avg_tier'] }}"
                                  style="font-size:0.7rem; font-weight:700; color:{{ $badgeColor }};
                                         padding:0.2rem 0.55rem; border-radius:4px;
                                         background:rgba(0,0,0,0.30);
                                         border:1px solid {{ $badgeColor }}33;
                                         letter-spacing:0.04em;">
                                « {{ $ob['name'] }} »
                            </span>
                        @endif
                    </div>
                    <div style="font-size:0.65rem; color:#9ca3af;">scopes granted: {{ implode(', ', $scopes_granted ?: ['publicData']) }}</div>
                </div>
                <form method="post" action="/war-report/{{ $conflict }}/logout" style="margin:0;">
                    @csrf
                    <button type="submit" style="font-size:0.65rem; color:#fca5a5; background:rgba(252,165,165,0.05); border:1px solid rgba(252,165,165,0.30); padding:0.4rem 0.8rem; border-radius:5px; cursor:pointer;">sign out</button>
                </form>
            </div>

            {{-- Tabbed view — pure CSS via radio+labels (CSP allows
                 it; no JS needed). Sections are siblings of the
                 radios so :checked ~ selectors work. --}}
            <div class="aegis-tabs">
                <input type="radio" name="atab" id="atab-overview" checked>
                <input type="radio" name="atab" id="atab-combat">
                <input type="radio" name="atab" id="atab-killboard">
                <input type="radio" name="atab" id="atab-social">
                <input type="radio" name="atab" id="atab-map">
                <nav class="aegis-tab-nav">
                    <label for="atab-overview">Overview</label>
                    <label for="atab-combat">Combat profile</label>
                    <label for="atab-killboard">Killboard</label>
                    <label for="atab-social">Social</label>
                    <label for="atab-map">Map</label>
                </nav>

                {{-- ───────────── OVERVIEW ───────────── --}}
                <section data-tab="atab-overview">

            {{-- Top stats --}}
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:0.6rem; margin-bottom:1.25rem;">
                <div class="stat-card">
                    <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">Kills you were on</div>
                    <div style="font-size:1.4rem; color:#86efac; font-weight:700;">{{ $fmtNum($totalKills) }}</div>
                    <div style="font-size:0.6rem; color:#9ca3af;">total ISK on grid: <strong style="color:#fde68a;">{{ $fmtIsk((float) ($stats['isk_involved'] ?? 0)) }}</strong></div>
                </div>
                <div class="stat-card">
                    <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">Final blows landed</div>
                    <div style="font-size:1.4rem; color:#fde68a; font-weight:700;">{{ $fmtNum((int) $stats['final_blows']) }}</div>
                    <div style="font-size:0.6rem; color:#9ca3af;">credited ISK: <strong style="color:#fde68a;">{{ $fmtIsk((float) $stats['isk_destroyed']) }}</strong></div>
                </div>
                <div class="stat-card">
                    <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">Losses</div>
                    <div style="font-size:1.4rem; color:#fca5a5; font-weight:700;">{{ $fmtNum($totalLosses) }} <span style="font-size:0.7rem; color:#7a7a82;">· {{ $fmtIsk((float) $stats['isk_lost']) }}</span></div>
                </div>
                <div class="stat-card">
                    <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">Battles attended</div>
                    <div style="font-size:1.4rem; color:#7dd3fc; font-weight:700;">{{ $fmtNum((int) $stats['battles_attended']) }} <span style="font-size:0.7rem; color:#7a7a82;">· {{ $stats['battle_attendance_pct'] }}%</span></div>
                </div>
                <div class="stat-card">
                    <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">Small-gang kills (≤5 attackers)</div>
                    <div style="font-size:1.4rem; color:#a5b4fc; font-weight:700;">{{ $fmtNum((int) $stats['small_gang_kills']) }}</div>
                </div>
                <div class="stat-card">
                    <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">ISK efficiency</div>
                    <div style="font-size:1.4rem; color:#e5e5e7; font-weight:700;">{{ $efficiencyIsk }}%</div>
                </div>
            </div>

            {{-- Daily activity vs alliance — two-line SVG. Y-axis
                 Stays inside Overview pane so personal-trend
                 insights live with the stats. --}}
            @php /* still inside Overview pane */ @endphp
            {{-- DAILY-AVG SECTION START --}}
                 auto-scaled to the larger of the two series. --}}
            @if (! empty($stats['daily_activity']['days']))
                @php
                    $da = $stats['daily_activity'];
                    $maxY = max(array_merge([1], $da['self'], array_map(fn ($v) => (float) $v, $da['alliance_avg'])));
                    $w = 1000; $h = 220; $pad = 32;
                    $plotW = $w - 2 * $pad; $plotH = $h - 2 * $pad;
                    $n = max(1, count($da['days']) - 1);
                    $xAt = fn ($i) => $pad + ($i / $n) * $plotW;
                    $yAt = fn ($v) => $h - $pad - ($v / $maxY) * $plotH;
                    $selfPath = '';
                    foreach ($da['self'] as $i => $v) {
                        $selfPath .= ($i === 0 ? 'M' : ' L') . round($xAt($i), 1) . ' ' . round($yAt((float) $v), 1);
                    }
                    $allPath = '';
                    foreach ($da['alliance_avg'] as $i => $v) {
                        $allPath .= ($i === 0 ? 'M' : ' L') . round($xAt($i), 1) . ' ' . round($yAt((float) $v), 1);
                    }
                @endphp
                <h2 style="margin:0.5rem 0 0.6rem 0; font-size:0.95rem; color:#e5e5e7;">You vs {{ $da['alliance_name'] ?: 'your alliance' }} — daily kills</h2>
                <div style="margin-bottom:1.5rem; padding:0.7rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(0,0,0,0.30);">
                    <div style="display:flex; gap:1rem; margin-bottom:0.4rem; font-size:0.6rem; color:#9ca3af;">
                        <span><span style="display:inline-block; width:10px; height:2px; background:#86efac; vertical-align:middle; margin-right:0.3rem;"></span>You</span>
                        <span><span style="display:inline-block; width:10px; height:2px; background:#7dd3fc; vertical-align:middle; margin-right:0.3rem;"></span>{{ $da['alliance_name'] ?: 'Alliance' }} avg per pilot</span>
                    </div>
                    <svg viewBox="0 0 {{ $w }} {{ $h }}" xmlns="http://www.w3.org/2000/svg" style="width:100%; height:auto; display:block;">
                        <rect x="0" y="0" width="{{ $w }}" height="{{ $h }}" fill="rgba(0,0,0,0.20)"/>
                        {{-- Y axis baseline --}}
                        <line x1="{{ $pad }}" y1="{{ $h - $pad }}" x2="{{ $w - $pad }}" y2="{{ $h - $pad }}"
                              stroke="rgba(255,255,255,0.10)" stroke-width="1"/>
                        {{-- Grid quartiles --}}
                        @for ($q = 1; $q <= 3; $q++)
                            @php $gy = $pad + ($plotH * $q / 4); @endphp
                            <line x1="{{ $pad }}" y1="{{ $gy }}" x2="{{ $w - $pad }}" y2="{{ $gy }}"
                                  stroke="rgba(255,255,255,0.04)" stroke-dasharray="2 4"/>
                        @endfor
                        {{-- Alliance avg --}}
                        <path d="{{ $allPath }}" fill="none" stroke="#7dd3fc" stroke-width="2" stroke-linejoin="round" opacity="0.85"/>
                        {{-- Self --}}
                        <path d="{{ $selfPath }}" fill="none" stroke="#86efac" stroke-width="2.5" stroke-linejoin="round"/>
                        {{-- Y-axis max label --}}
                        <text x="{{ $pad + 4 }}" y="{{ $pad + 10 }}" font-size="9" fill="#7a7a82" font-family="monospace">{{ number_format((float) $maxY, 1) }} / day</text>
                        {{-- X-axis labels (first + last) --}}
                        <text x="{{ $pad }}" y="{{ $h - 8 }}" font-size="9" fill="#7a7a82" font-family="monospace">{{ $da['days'][0] }}</text>
                        <text x="{{ $w - $pad - 60 }}" y="{{ $h - 8 }}" font-size="9" fill="#7a7a82" font-family="monospace">{{ end($da['days']) }}</text>
                    </svg>
                </div>
            @endif

                </section>
                {{-- ───────────── MAP ───────────── --}}
                <section data-tab="atab-map">

            {{-- Activity map — same SVG region map the portal uses,
                 scoped to this conflict's window. Ansiblex overlays
                 are operational intel; strip them before rendering on
                 the public mirror. --}}
            @if (! empty($stats['activity_map']['regions']))
                @php
                    $publicMap = $stats['activity_map'];
                    foreach ($publicMap['regions'] as &$_region) {
                        $_region['ansiblex'] = [];
                    }
                    unset($_region);
                @endphp
                <h2 style="margin:0.5rem 0 0.6rem 0; font-size:0.95rem; color:#e5e5e7;">{{ $footprint_title }}</h2>
                <div style="margin-bottom:1.5rem; padding:0.7rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(0,0,0,0.20);">
                    @include('filament.portal.partials.activity-map', ['c' => $publicMap, 'mapLayout' => 'two-up'])
                </div>
            @endif

                </section>
                {{-- ───────────── SOCIAL ───────────── --}}
                <section data-tab="atab-social">

            {{-- Best buddies + arch enemies --}}
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(380px, 1fr)); gap:0.7rem; margin-bottom:1.5rem;">
                @if (! empty($stats['top_buddies']))
                    <div style="padding:0.85rem 1rem; border:1px solid rgba(134,239,172,0.20); border-radius:8px; background:rgba(0,0,0,0.30);">
                        <h3 style="margin:0 0 0.6rem 0; font-size:0.95rem; color:#86efac;">{{ $buddy_title }}</h3>
                        @foreach ($stats['top_buddies'] as $i => $b)
                            <div style="display:flex; align-items:center; gap:0.5rem; padding:0.3rem 0; border-bottom:1px solid rgba(255,255,255,0.04); font-size:0.7rem;">
                                <span style="flex:0 0 18px; color:#7a7a82; font-size:0.6rem;">#{{ $i + 1 }}</span>
                                <img src="/img/character/{{ $b->id }}?size=64" alt="" loading="lazy" referrerpolicy="no-referrer" style="width:28px; height:28px; border-radius:50%; flex:0 0 28px;">
                                <div style="flex:1; min-width:0;">
                                    <div style="color:#e5e5e7; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $b->name ?: '#'.$b->id }}</div>
                                    <div style="font-size:0.55rem; color:#7a7a82; display:flex; align-items:center; gap:0.25rem;">
                                        @if ($b->alliance_id)
                                            <img src="/img/alliance/{{ $b->alliance_id }}?size=32" alt="" loading="lazy" referrerpolicy="no-referrer" style="width:12px; height:12px;">
                                        @endif
                                        <span>{{ $b->alliance_name ?: '—' }}</span>
                                    </div>
                                </div>
                                <div style="flex:0 0 80px; text-align:right; color:#86efac; font-weight:700;">{{ $fmtNum((int) $b->shared_kms) }} <span style="font-size:0.55rem; color:#7a7a82;">shared</span></div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if (! empty($stats['top_enemies']))
                    <div style="padding:0.85rem 1rem; border:1px solid rgba(252,165,165,0.20); border-radius:8px; background:rgba(0,0,0,0.30);">
                        <h3 style="margin:0 0 0.6rem 0; font-size:0.95rem; color:#fca5a5;">{{ $enemy_title }}</h3>
                        @foreach ($stats['top_enemies'] as $i => $e)
                            <div style="display:flex; align-items:center; gap:0.5rem; padding:0.3rem 0; border-bottom:1px solid rgba(255,255,255,0.04); font-size:0.7rem;">
                                <span style="flex:0 0 18px; color:#7a7a82; font-size:0.6rem;">#{{ $i + 1 }}</span>
                                <img src="/img/character/{{ $e->id }}?size=64" alt="" loading="lazy" referrerpolicy="no-referrer" style="width:28px; height:28px; border-radius:50%; flex:0 0 28px;">
                                <div style="flex:1; min-width:0;">
                                    <div style="color:#e5e5e7; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $e->name ?: '#'.$e->id }}</div>
                                    <div style="font-size:0.55rem; color:#7a7a82; display:flex; align-items:center; gap:0.25rem;">
                                        @if ($e->alliance_id)
                                            <img src="/img/alliance/{{ $e->alliance_id }}?size=32" alt="" loading="lazy" referrerpolicy="no-referrer" style="width:12px; height:12px;">
                                        @endif
                                        <span>{{ $e->alliance_name ?: '—' }}</span>
                                    </div>
                                </div>
                                <div style="flex:0 0 80px; text-align:right; color:#fca5a5; font-weight:700;">{{ $fmtNum((int) $e->encounters) }} <span style="font-size:0.55rem; color:#7a7a82;">fights</span></div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

                </section>
                {{-- ───────── MAP (continuation: top systems) ───── --}}
                <section data-tab="atab-map">

            {{-- Top systems where you fought --}}
            @if (! empty($stats['top_systems']))
                <h2 style="margin:0.5rem 0 0.6rem 0; font-size:0.95rem; color:#e5e5e7;">Where you fought</h2>
                <p style="margin:0 0 0.8rem 0; font-size:0.65rem; color:#9ca3af;">Top systems by killmails you were on, attacker-side.</p>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:0.4rem; margin-bottom:1.5rem;">
                    @php
                        $sysSevColor = function (?float $sec): string {
                            if ($sec === null) return '#9ca3af';
                            if ($sec >= 0.5) return '#86efac';
                            if ($sec >= 0.0) return '#fdba74';
                            return '#fca5a5';
                        };
                    @endphp
                    @foreach ($stats['top_systems'] as $s)
                        <div style="padding:0.55rem 0.75rem; border:1px solid rgba(255,255,255,0.06); border-radius:6px; background:rgba(0,0,0,0.20);">
                            <div style="font-size:0.85rem; font-weight:700; color:{{ $sysSevColor($s->security_status) }};">{{ $s->name }}</div>
                            <div style="font-size:0.6rem; color:#9ca3af; margin-top:0.15rem;">
                                <strong style="color:#e5e5e7;">{{ $fmtNum((int) $s->kills) }}</strong> kills
                                <span style="color:#7a7a82;">· sec {{ number_format((float) $s->security_status, 1) }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

                </section>
                {{-- ───────────── KILLBOARD ───────────── --}}
                <section data-tab="atab-killboard">

            {{-- Killboard slices: top + latest, kills + losses --}}
            @php
                $renderKillRow = function ($r, $isLoss = false) use ($fmtIsk) {
                    $shipUrl = ! empty($r->victim_ship_type_id) ? '/img/type/'.$r->victim_ship_type_id.'?size=64' : null;
                    return [
                        'href' => '/kills/' . $r->killmail_id,
                        'ship' => $r->victim_ship_type_name ?: '?',
                        'ship_icon' => $shipUrl,
                        'system' => $r->system_name,
                        'isk' => $fmtIsk((float) $r->total_value),
                        'when' => \Carbon\Carbon::parse($r->killed_at)->format('M d H:i'),
                        'who' => $isLoss ? ($r->fb_char_name ?: 'unknown FB') : ($r->victim_name ?: 'unknown victim'),
                        'who_alliance' => $isLoss ? ($r->fb_alliance_name ?: '—') : ($r->victim_alliance_name ?: '—'),
                        'who_alliance_id' => $isLoss ? ($r->fb_alliance_id ?? null) : ($r->victim_alliance_id ?? null),
                    ];
                };
                $killSections = [
                    ['title' => '🥊 Your top 10 kills (by ISK)', 'rows' => $stats['top_isk_kills'] ?? [], 'tint' => '#86efac', 'isLoss' => false],
                    ['title' => '💥 Your top 10 losses (by ISK)', 'rows' => $stats['top_isk_losses'] ?? [], 'tint' => '#fca5a5', 'isLoss' => true],
                    ['title' => '⚡ Your 10 latest kills', 'rows' => $stats['latest_kills'] ?? [], 'tint' => '#86efac', 'isLoss' => false],
                    ['title' => '☠ Your 10 latest losses', 'rows' => $stats['latest_losses'] ?? [], 'tint' => '#fca5a5', 'isLoss' => true],
                ];
            @endphp
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(360px, 1fr)); gap:0.7rem; margin-bottom:1.5rem;">
                @foreach ($killSections as $sec)
                    @if (count($sec['rows']) === 0) @continue @endif
                    <div style="padding:0.85rem 1rem; border:1px solid {{ $sec['tint'] }}33; border-radius:8px; background:rgba(0,0,0,0.30);">
                        <h3 style="margin:0 0 0.5rem 0; font-size:0.85rem; color:{{ $sec['tint'] }};">{{ $sec['title'] }}</h3>
                        @foreach ($sec['rows'] as $i => $r)
                            @php $row = $renderKillRow($r, $sec['isLoss']); @endphp
                            <a href="{{ $row['href'] }}" style="display:flex; gap:0.5rem; padding:0.3rem 0; border-bottom:1px solid rgba(255,255,255,0.04); text-decoration:none; color:inherit; align-items:center;">
                                <span style="flex:0 0 18px; color:#7a7a82; font-size:0.6rem;">#{{ $i + 1 }}</span>
                                @if ($row['ship_icon'])
                                    <img src="{{ $row['ship_icon'] }}" loading="lazy" referrerpolicy="no-referrer" alt="" style="width:24px; height:24px; flex:0 0 24px;">
                                @endif
                                <div style="flex:1; min-width:0;">
                                    <div style="font-size:0.7rem; color:#cbd5e1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                        <span style="color:#fde68a; font-weight:700;">{{ $row['isk'] }}</span>
                                        <span style="color:#7dd3fc;"> · {{ $row['system'] }}</span>
                                        <span style="color:#cbd5e1;"> · {{ $row['ship'] }}</span>
                                    </div>
                                    <div style="font-size:0.55rem; color:#9ca3af; display:flex; align-items:center; gap:0.25rem;">
                                        @if ($row['who_alliance_id'])
                                            <img src="/img/alliance/{{ $row['who_alliance_id'] }}?size=32" loading="lazy" referrerpolicy="no-referrer" alt="" style="width:11px; height:11px;">
                                        @endif
                                        <span>{{ $row['who'] }}</span>
                                        <span style="color:#7a7a82;">· {{ $row['who_alliance'] }}</span>
                                        <span style="color:#7a7a82; margin-left:auto;">{{ $row['when'] }}</span>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </div>
            <div style="text-align:center; margin:0.5rem 0 1.5rem;">
                <a href="/war-report/{{ $conflict }}/me/killboard"
                   style="display:inline-block; padding:0.5rem 1rem; border:1px solid rgba(125,211,252,0.30); border-radius:5px; background:rgba(125,211,252,0.05); color:#7dd3fc; text-decoration:none; font-size:0.7rem; letter-spacing:0.04em;">View full killboard →</a>
            </div>

                </section>
                {{-- ───────────── COMBAT PROFILE ───────────── --}}
                <section data-tab="atab-combat">

            {{-- Tactical personality + ship mastery + role + battles
                 cluster here, ahead of the badges proper. --}}
            @if (! empty($stats['tactical_traits']))
                <h3 style="margin:0.4rem 0 0.5rem 0; font-size:0.85rem; color:#e5e5e7;">⚔ Tactical personality</h3>
                <p style="font-size:0.6rem; color:#7a7a82; margin:0 0 0.6rem 0;">Heuristic — derived from killmail patterns, not calibrated.</p>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:0.5rem; margin-bottom:1.5rem;">
                    @foreach ($stats['tactical_traits'] as $trait => $t)
                        @php
                            $color = $t['value'] >= 80 ? '#fde68a' : ($t['value'] >= 60 ? '#fdba74' : ($t['value'] >= 40 ? '#cbd5e1' : '#9ca3af'));
                        @endphp
                        <div style="padding:0.55rem 0.75rem; border:1px solid rgba(255,255,255,0.08); border-radius:6px; background:rgba(0,0,0,0.30);">
                            <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em;">{{ str_replace('_', ' ', $trait) }}</div>
                            <div style="display:flex; align-items:baseline; gap:0.4rem; margin-top:0.15rem;">
                                <span style="font-size:1.05rem; font-weight:700; color:{{ $color }};">{{ $t['label'] }}</span>
                                <span style="font-size:0.6rem; color:#7a7a82;">{{ $t['value'] }}/100</span>
                            </div>
                            <div style="height:6px; background:rgba(255,255,255,0.06); border-radius:3px; overflow:hidden; margin-top:0.3rem;">
                                <div style="height:100%; width:{{ $t['value'] }}%; background:{{ $color }}; opacity:0.65;"></div>
                            </div>
                            <div style="font-size:0.55rem; color:#7a7a82; margin-top:0.3rem; font-style:italic;">{{ $t['why'] }}</div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if (! empty($stats['role_breakdown']))
                <h3 style="margin:0.4rem 0 0.5rem 0; font-size:0.85rem; color:#e5e5e7;">🎯 Combat role detection</h3>
                <p style="font-size:0.6rem; color:#7a7a82; margin:0 0 0.6rem 0;">Bucket of victim ship classes you killed — what role you most often played on the field.</p>
                <div style="margin-bottom:1.5rem; padding:0.7rem 0.85rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(0,0,0,0.20);">
                    @foreach ($stats['role_breakdown'] as $bucket => $data)
                        @php $w = max(2, (int) round($data['pct'])); @endphp
                        <div style="display:flex; align-items:center; gap:0.5rem; font-size:0.65rem; padding:0.2rem 0;">
                            <div style="flex:0 0 160px; color:#cbd5e1; font-weight:600;">{{ $bucket }}</div>
                            <div style="flex:1; height:11px; background:rgba(255,255,255,0.04); border-radius:2px; overflow:hidden;">
                                <div style="height:100%; width:{{ $w }}%; background:#86efac; opacity:0.6;"></div>
                            </div>
                            <div style="flex:0 0 60px; text-align:right; color:#e5e5e7; font-weight:600;">{{ $data['pct'] }}%</div>
                            <div style="flex:0 0 56px; text-align:right; color:#7a7a82;">{{ $fmtNum($data['count']) }} km</div>
                            <div style="flex:0 0 220px; color:#7a7a82; font-size:0.55rem; font-style:italic; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ implode(', ', $data['top_examples']) }}</div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if (! empty($stats['ship_mastery']))
                <h3 style="margin:0.4rem 0 0.5rem 0; font-size:0.85rem; color:#e5e5e7;">🚀 Ship mastery</h3>
                <p style="font-size:0.6rem; color:#7a7a82; margin:0 0 0.6rem 0;">Hulls you actually flew during this conflict — kills + losses per ship type.</p>
                <div style="margin-bottom:1.5rem; padding:0.7rem 0.85rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(0,0,0,0.20);">
                    <div style="display:grid; grid-template-columns:1.2fr 0.6fr 0.7fr 0.6fr 0.7fr; gap:0.4rem; font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em; padding-bottom:0.3rem; border-bottom:1px solid rgba(255,255,255,0.06);">
                        <div>Hull</div><div style="text-align:right;">Kills</div><div style="text-align:right;">Kill ISK</div><div style="text-align:right;">Losses</div><div style="text-align:right;">Loss ISK</div>
                    </div>
                    @foreach ($stats['ship_mastery'] as $sm)
                        <div style="display:grid; grid-template-columns:1.2fr 0.6fr 0.7fr 0.6fr 0.7fr; gap:0.4rem; font-size:0.7rem; padding:0.25rem 0; border-bottom:1px solid rgba(255,255,255,0.04); align-items:center;">
                            <div style="display:flex; align-items:center; gap:0.4rem;">
                                @if ($sm->type_id)
                                    <img src="/img/type/{{ $sm->type_id }}?size=32" loading="lazy" alt="" style="width:18px; height:18px;">
                                @endif
                                <div>
                                    <div style="color:#e5e5e7; font-weight:600;">{{ $sm->type_name }}</div>
                                    <div style="font-size:0.5rem; color:#7a7a82;">{{ $sm->group_name }}</div>
                                </div>
                            </div>
                            <div style="text-align:right; color:#86efac; font-weight:700;">{{ $fmtNum((int) $sm->kills) }}</div>
                            <div style="text-align:right; color:#fde68a;">{{ $fmtIsk((float) $sm->kill_isk) }}</div>
                            <div style="text-align:right; color:#fca5a5; font-weight:700;">{{ $fmtNum((int) $sm->losses) }}</div>
                            <div style="text-align:right; color:#fde68a;">{{ $fmtIsk((float) $sm->loss_isk) }}</div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if (! empty($stats['big_battles']))
                <h3 style="margin:0.4rem 0 0.5rem 0; font-size:0.85rem; color:#e5e5e7;">🏟 You were there for...</h3>
                <p style="font-size:0.6rem; color:#7a7a82; margin:0 0 0.6rem 0;">Top 5 biggest battles you appeared in (by total killmail count).</p>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:0.5rem; margin-bottom:1.5rem;">
                    @foreach ($stats['big_battles'] as $b)
                        <a href="/battles/{{ $b->public_slug ?: $b->id }}" style="display:block; padding:0.6rem 0.8rem; border:1px solid rgba(253,224,71,0.20); border-radius:6px; background:rgba(0,0,0,0.30); text-decoration:none; color:inherit;">
                            <div style="display:flex; gap:0.4rem; align-items:baseline;">
                                <span style="font-size:0.85rem; font-weight:700; color:#fde68a;">{{ $b->system_name }}</span>
                                <span style="font-size:0.6rem; color:#cbd5e1;">{{ $fmtNum((int) ($b->total_kills ?: 0)) }} kms</span>
                                <span style="font-size:0.6rem; color:#fca5a5;">{{ $fmtIsk((float) ($b->total_isk_lost ?: 0)) }}</span>
                            </div>
                            <div style="font-size:0.6rem; color:#9ca3af; margin-top:0.2rem;">
                                You were on <strong style="color:#86efac;">{{ $fmtNum((int) $b->my_kms) }}</strong> killmails ·
                                {{ \Carbon\Carbon::parse($b->start_time)->format('M d') }}
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif

            {{-- Badges --}}
            <h2 style="margin:0.5rem 0 0.6rem 0; font-size:0.95rem; color:#e5e5e7;">Your badges</h2>
            <p style="margin:0 0 0.8rem 0; font-size:0.65rem; color:#9ca3af;">Each tier reflects your percentile rank vs every pilot in this conflict. Top stays EVE-flavored, lower tiers go full reddit-meme — wear them with pride. Each card shows what you'd need to reach the next tier.</p>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:0.6rem;">
                @foreach ($badges as $b)
                    @php
                        $isIsk = $b['metric'] === 'isk_destroyed';
                        $valueFmt = $isIsk ? $fmtIsk((float) $b['value']) : $fmtNum((int) $b['value']);
                    @endphp
                    <div class="badge-card">
                        <div class="badge-tier-strip tier-{{ $b['tier'] }}"></div>
                        <div style="margin-left:0.4rem;">
                            <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">
                                {{ str_replace('_', ' ', $b['metric']) }} · tier {{ $b['tier'] }}/10
                            </div>
                            <div style="font-size:1rem; color:#e5e5e7; font-weight:700; margin-top:0.15rem;">{{ $b['name'] }}</div>
                            <div style="font-size:0.65rem; color:#9ca3af; margin-top:0.2rem;">{{ $b['sub'] }}</div>
                            <div style="font-size:0.6rem; color:#7a7a82; margin-top:0.35rem;">
                                value <strong style="color:#e5e5e7;">{{ $valueFmt }}</strong>
                                · percentile <strong style="color:#e5e5e7;">{{ $b['percentile'] }}%</strong>
                            </div>
                            @if (! empty($b['next_name']) && $b['next_delta'] !== null)
                                @php
                                    $deltaFmt = $isIsk
                                        ? $fmtIsk((float) $b['next_delta'])
                                        : $fmtNum((int) ceil((float) $b['next_delta']));
                                    $thresholdFmt = $isIsk
                                        ? $fmtIsk((float) $b['next_threshold'])
                                        : $fmtNum((int) $b['next_threshold']);
                                @endphp
                                <div style="margin-top:0.5rem; padding:0.4rem 0.5rem; border-radius:5px; background:rgba(99,102,241,0.08); border:1px solid rgba(99,102,241,0.20); font-size:0.6rem; color:#cbd5e1;">
                                    Next: <strong style="color:#c7d2fe;">{{ $b['next_name'] }}</strong>
                                    · need <strong style="color:#fde68a;">+{{ $deltaFmt }}</strong>
                                    (reach {{ $thresholdFmt }})
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
                </section>{{-- close combat tab --}}
            </div>{{-- close .aegis-tabs --}}
        @endif
    </div>
</body>
</html>

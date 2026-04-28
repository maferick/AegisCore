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
                    <div style="font-size:1.15rem; font-weight:700; color:#e5e5e7;">{{ $character_name }}</div>
                    <div style="font-size:0.65rem; color:#9ca3af;">scopes granted: {{ implode(', ', $scopes_granted ?: ['publicData']) }}</div>
                </div>
                <form method="post" action="/war-report/{{ $conflict }}/logout" style="margin:0;">
                    @csrf
                    <button type="submit" style="font-size:0.65rem; color:#fca5a5; background:rgba(252,165,165,0.05); border:1px solid rgba(252,165,165,0.30); padding:0.4rem 0.8rem; border-radius:5px; cursor:pointer;">sign out</button>
                </form>
            </div>

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
        @endif
    </div>
</body>
</html>

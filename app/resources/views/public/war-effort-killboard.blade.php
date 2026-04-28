<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $character_name }} — full killboard ({{ $display_label }})</title>
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
        .container { max-width: 1280px; margin: 0 auto; padding: 1.5rem 1.25rem 4rem; position: relative; z-index: 1; }
        .public-header { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:0.5rem 0 1rem; border-bottom:1px solid rgba(255,255,255,0.06); margin-bottom:1.25rem; flex-wrap:wrap; }
        .public-header h1 { margin:0; font-size:1rem; font-weight:600; letter-spacing:0.04em; }
        a { color: inherit; }
        table.km-table { width:100%; border-collapse: collapse; font-size:0.7rem; }
        table.km-table th, table.km-table td { padding:0.35rem 0.6rem; border-bottom:1px solid rgba(255,255,255,0.04); text-align:left; vertical-align:middle; }
        table.km-table th { font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em; background:rgba(0,0,0,0.30); position:sticky; top:0; }
        table.km-table tr:hover td { background:rgba(255,255,255,0.02); }
        .km-section { margin-bottom:1.5rem; padding:0.85rem 1rem; border:1px solid rgba(255,255,255,0.06); border-radius:8px; background:rgba(0,0,0,0.20); }
        .km-section h2 { margin:0 0 0.4rem 0; font-size:0.95rem; }
        .scroll-table { max-height:560px; overflow-y:auto; border:1px solid rgba(255,255,255,0.04); border-radius:5px; }
    </style>
    @include('partials.aegis-public-bg')
</head>
<body class="aegis-public-bg" data-page="{{ $page_class }}">
    <div class="container">
        <div class="public-header">
            <h1>⚔ {{ $character_name }} — full killboard ({{ $display_label }})</h1>
            <span style="font-size:0.65rem; color:#7a7a82;">
                <a href="/war-report/{{ $conflict }}/me" style="color:#7dd3fc; text-decoration:none;">← back to your effort</a>
            </span>
        </div>

        @php
            $fmtIsk = function (float $v): string {
                if ($v >= 1e12) return number_format($v / 1e12, 2) . ' T';
                if ($v >= 1e9)  return number_format($v / 1e9, 2) . ' B';
                if ($v >= 1e6)  return number_format($v / 1e6, 2) . ' M';
                return number_format($v, 0);
            };
            $totalKillIsk = array_sum(array_map(fn ($r) => (float) $r->total_value, $kills));
            $totalLossIsk = array_sum(array_map(fn ($r) => (float) $r->total_value, $losses));
        @endphp

        <div style="display:flex; gap:0.6rem; margin-bottom:1rem; flex-wrap:wrap;">
            <div style="padding:0.5rem 0.8rem; border:1px solid rgba(134,239,172,0.30); border-radius:5px; background:rgba(0,0,0,0.30);">
                <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em;">Kills (you on grid)</div>
                <div style="font-size:1rem; color:#86efac; font-weight:700;">{{ count($kills) }} <span style="font-size:0.65rem; color:#fde68a;">· {{ $fmtIsk($totalKillIsk) }}</span></div>
            </div>
            <div style="padding:0.5rem 0.8rem; border:1px solid rgba(252,165,165,0.30); border-radius:5px; background:rgba(0,0,0,0.30);">
                <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em;">Losses (your hull)</div>
                <div style="font-size:1rem; color:#fca5a5; font-weight:700;">{{ count($losses) }} <span style="font-size:0.65rem; color:#fde68a;">· {{ $fmtIsk($totalLossIsk) }}</span></div>
            </div>
        </div>

        <div class="km-section">
            <h2 style="color:#86efac;">Kills</h2>
            <p style="font-size:0.6rem; color:#7a7a82; margin:0 0 0.4rem 0;">Every war-attributable killmail you were on as an attacker, newest first.</p>
            <div class="scroll-table">
                <table class="km-table">
                    <thead><tr><th>When</th><th>System</th><th>Victim ship</th><th>Victim</th><th>Alliance</th><th style="text-align:right;">ISK</th></tr></thead>
                    <tbody>
                        @foreach ($kills as $k)
                            <tr>
                                <td style="color:#7a7a82; white-space:nowrap;">{{ \Carbon\Carbon::parse($k->killed_at)->format('M d H:i') }}</td>
                                <td style="color:#7dd3fc;">{{ $k->system_name }}</td>
                                <td>
                                    @if (! empty($k->victim_ship_type_id))
                                        <img src="/img/type/{{ $k->victim_ship_type_id }}?size=32" loading="lazy" alt="" style="width:14px; height:14px; vertical-align:middle; margin-right:0.25rem;">
                                    @endif
                                    <a href="/kills/{{ $k->killmail_id }}" style="color:#cbd5e1; text-decoration:none;">{{ $k->victim_ship_type_name ?: '?' }}</a>
                                </td>
                                <td style="color:#cbd5e1;">{{ $k->victim_name ?: '—' }}</td>
                                <td>
                                    @if (! empty($k->victim_alliance_id))
                                        <img src="/img/alliance/{{ $k->victim_alliance_id }}?size=32" loading="lazy" alt="" style="width:11px; height:11px; vertical-align:middle; margin-right:0.2rem;">
                                    @endif
                                    <span style="color:#9ca3af;">{{ $k->victim_alliance_name ?: '—' }}</span>
                                </td>
                                <td style="text-align:right; color:#fde68a; font-weight:700;">{{ $fmtIsk((float) $k->total_value) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="km-section">
            <h2 style="color:#fca5a5;">Losses</h2>
            <p style="font-size:0.6rem; color:#7a7a82; margin:0 0 0.4rem 0;">Every war-attributable killmail where you were the victim, newest first.</p>
            @if (count($losses) === 0)
                <p style="font-style:italic; color:#7a7a82; font-size:0.7rem;">None. Either you're a god, or you were lucky.</p>
            @else
                <div class="scroll-table">
                    <table class="km-table">
                        <thead><tr><th>When</th><th>System</th><th>Your ship</th><th>FB by</th><th>Alliance</th><th style="text-align:right;">ISK</th></tr></thead>
                        <tbody>
                            @foreach ($losses as $l)
                                <tr>
                                    <td style="color:#7a7a82; white-space:nowrap;">{{ \Carbon\Carbon::parse($l->killed_at)->format('M d H:i') }}</td>
                                    <td style="color:#7dd3fc;">{{ $l->system_name }}</td>
                                    <td>
                                        @if (! empty($l->victim_ship_type_id))
                                            <img src="/img/type/{{ $l->victim_ship_type_id }}?size=32" loading="lazy" alt="" style="width:14px; height:14px; vertical-align:middle; margin-right:0.25rem;">
                                        @endif
                                        <a href="/kills/{{ $l->killmail_id }}" style="color:#cbd5e1; text-decoration:none;">{{ $l->victim_ship_type_name ?: '?' }}</a>
                                    </td>
                                    <td style="color:#cbd5e1;">{{ $l->fb_char_name ?: '—' }}</td>
                                    <td>
                                        @if (! empty($l->fb_alliance_id))
                                            <img src="/img/alliance/{{ $l->fb_alliance_id }}?size=32" loading="lazy" alt="" style="width:11px; height:11px; vertical-align:middle; margin-right:0.2rem;">
                                        @endif
                                        <span style="color:#9ca3af;">{{ $l->fb_alliance_name ?: '—' }}</span>
                                    </td>
                                    <td style="text-align:right; color:#fde68a; font-weight:700;">{{ $fmtIsk((float) $l->total_value) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</body>
</html>

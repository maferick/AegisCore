<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $display_label ?? 'War Report' }} — killsineve.online</title>
    <meta name="description" content="Live war report — {{ $display_label ?? 'WinterCo vs Imperium' }}. Conflict floor 2026-04-02.">
    <meta name="robots" content="index, follow">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Ctext y='13' font-size='14'%3E%E2%9A%94%3C/text%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700;900&family=Rajdhani:wght@400;500;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link href="/css/hud.css?v=3" rel="stylesheet">
    <link href="/css/hud-elevated.css?v=4" rel="stylesheet">
    <script src="/js/auto-refresh.js?v=1" defer></script>
    <style>
        :root {
            color-scheme: dark;
            --bg-deep: #050913;
            --bg-panel: #06090f;
            --bg-card: rgba(8,12,22,0.80);
            --hud-cyan: #6dd6ff;
            --hud-cyan-soft: rgba(109,214,255,0.20);
            --hud-magenta: #c474a8;
            --hud-magenta-soft: rgba(196,116,168,0.20);
            --hud-gold: #f4c75c;
            --hud-gold-soft: rgba(244,199,92,0.18);
            --hud-platinum: #d6dbe4;
            --hud-platinum-dim: #8c95a4;
            --hud-line: rgba(109,214,255,0.18);
            --font-head: 'Orbitron','Rajdhani',system-ui,sans-serif;
            --font-body: 'Rajdhani','Inter',system-ui,sans-serif;
            --font-mono: 'Share Tech Mono','JetBrains Mono',ui-monospace,monospace;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: var(--font-body);
            background:
                radial-gradient(rgba(109,214,255,0.04) 1px, transparent 1.5px) 0 0 / 28px 28px,
                radial-gradient(ellipse at 15% -10%, rgba(109,214,255,0.10) 0%, transparent 45%),
                radial-gradient(ellipse at 85% 110%, rgba(244,199,92,0.04) 0%, transparent 45%),
                var(--bg-deep);
            background-attachment: fixed;
            color: var(--hud-platinum);
            font-size: 0.9rem;
            line-height: 1.45;
        }
        .container {
            max-width: 1480px;
            margin: 0 auto;
            padding: 1.5rem 1.25rem 3rem;
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
        .public-header .meta {
            font-size: 0.65rem;
            color: #7a7a82;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        a { color: inherit; }
        code {
            background: rgba(255, 255, 255, 0.04);
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 0.7em;
        }
    </style>
    @include('partials.aegis-public-bg')
</head>
<body class="aegis-public-bg" data-page="{{ $conflict_key ?? 'war-report' }}" data-auto-refresh-seconds="180">
    {{-- Watermark logos — Fraternity (anchor of WinterCo, the panda
         coalition) on the left, opposing-bloc anchor on the right.
         Pulled via the local /img proxy so the page renders without
         hitting CCP for them. --}}
    @php
        $opposingAnchorId = match ($conflict_key ?? '') {
            'vs-imperium'   => 1354830081,  // Goonswarm Federation (bee)
            'vs-initiative' =>  1900696668, // The Initiative.
            default => null,
        };
    @endphp
    @if ($opposingAnchorId)
        <div class="aegis-watermarks" aria-hidden="true">
            <img class="aegis-watermark left"  src="/img/alliance/99003581?size=512" alt="" referrerpolicy="no-referrer">
            <img class="aegis-watermark right" src="/img/alliance/{{ $opposingAnchorId }}?size=512" alt="" referrerpolicy="no-referrer">
        </div>
    @endif
    <div class="container">
        <div class="public-header">
            <h1>⚔ {{ $display_label ?? 'War Report' }}</h1>
            <span class="meta"><a href="/war-report" style="color:#7dd3fc; text-decoration:none;">← all conflicts</a></span>
        </div>
        @include('partials.war-report-body')
    </div>
</body>
</html>

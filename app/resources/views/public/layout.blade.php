<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'killsineve.online')</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700;900&family=Rajdhani:wght@400;500;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link href="/css/hud.css?v=3" rel="stylesheet">
    <link href="/css/hud-elevated.css?v=4" rel="stylesheet">
    <script src="/js/auto-refresh.js?v=1" defer></script>
    <style>
        :root {
            /* Capsuleer-HUD palette. Side colors are calm so they
               don't fight each other. Gold is rare. Platinum carries
               all neutral data. */
            --bg-deep: #050913;            /* deep navy page background */
            --bg-panel: #06090f;           /* nested black-ish panel */
            --bg-card: rgba(8,12,22,0.80); /* card surface */
            --hud-cyan: #6dd6ff;           /* Side A / interactive */
            --hud-cyan-soft: rgba(109,214,255,0.20);
            --hud-magenta: #c474a8;        /* Side B (desaturated) */
            --hud-magenta-soft: rgba(196,116,168,0.20);
            --hud-gold: #f4c75c;           /* scarce achievement accent */
            --hud-gold-soft: rgba(244,199,92,0.18);
            --hud-platinum: #d6dbe4;       /* neutral data values */
            --hud-platinum-dim: #8c95a4;   /* secondary data */
            --hud-line: rgba(109,214,255,0.18); /* hairline cyan border */
            --hud-grid: rgba(109,214,255,0.04);

            /* Legacy aliases — keep for compatibility with old rules. */
            --bg: var(--bg-deep);
            --panel: var(--bg-card);
            --border: rgba(109,214,255,0.12);
            --muted: var(--hud-platinum-dim);
            --text: var(--hud-platinum);
            --cyan: var(--hud-cyan);
            --amber: var(--hud-gold);
            --red: var(--hud-magenta);
            --green: var(--hud-cyan);

            --font-head: 'Orbitron', 'Rajdhani', system-ui, sans-serif;
            --font-body: 'Rajdhani', 'Inter', system-ui, sans-serif;
            --font-mono: 'Share Tech Mono', 'JetBrains Mono', ui-monospace, monospace;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            /* Deep navy + faint hex-grid + corner glows. The grid uses
               two crossed gradients to fake a hex feel without asking
               the browser to render an SVG repeat. */
            background:
                radial-gradient(rgba(109,214,255,0.04) 1px, transparent 1.5px) 0 0 / 28px 28px,
                radial-gradient(ellipse at 15% -10%, rgba(109,214,255,0.10) 0%, transparent 45%),
                radial-gradient(ellipse at 85% 110%, rgba(244,199,92,0.04) 0%, transparent 45%),
                var(--bg-deep);
            background-attachment: fixed;
            color: var(--hud-platinum);
            font-family: var(--font-body);
            font-size: 14px;
            line-height: 1.5;
        }
        header.public-topbar {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.25rem;
            border-bottom: 1px solid rgba(79,208,208,0.15);
        }
        header.public-topbar .brand {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 700;
            letter-spacing: 0.08em;
            color: var(--cyan);
            text-decoration: none;
        }
        header.public-topbar nav {
            display: flex;
            gap: 1rem;
            margin-left: auto;
            font-size: 0.82rem;
        }
        header.public-topbar nav a {
            color: var(--muted);
            text-decoration: none;
        }
        header.public-topbar nav a:hover { color: var(--text); }
        main.public-main {
            max-width: 90%;
            margin: 1.5rem auto;
        }
        a { color: var(--cyan); }
        table.public-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            background: var(--panel);
        }
        .public-table th {
            text-align: left;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            padding: 0.6rem 0.9rem;
            border-bottom: 1px solid var(--border);
            font-family: 'JetBrains Mono', monospace;
        }
        .public-table td {
            padding: 0.6rem 0.9rem;
            border-bottom: 1px solid rgba(38,38,43,0.6);
            font-size: 0.85rem;
        }
        .public-table tr:last-child td { border-bottom: none; }
        .public-table tr.link-row { cursor: pointer; transition: background 0.1s; }
        .public-table tr.link-row:hover td { background: rgba(79,208,208,0.03); }
        .mono { font-family: 'JetBrains Mono', monospace; }
        .sec-hi { color: var(--green); }
        .sec-lo { color: var(--amber); }
        .sec-ns { color: var(--red); }
        .isk { color: var(--amber); }
        .loss { color: var(--red); }
        footer.public-footer {
            max-width: 90%;
            margin: 2rem auto 1rem;
            text-align: center;
            font-size: 0.72rem;
            color: var(--muted);
        }
    </style>
    @include('partials.aegis-public-bg')
    @stack('head')
</head>
<body class="aegis-public-bg" data-page="{{ $page_class ?? 'default' }}" data-auto-refresh-seconds="180">
    <header class="public-topbar">
        <a class="brand" href="/war-report">⚔ killsineve.online</a>
        <nav>
            <a href="/war-report">War reports</a>
            @if (! empty($battles_link))
                <a href="{{ $battles_link }}">{{ $battles_link_label ?? 'Battles' }}</a>
            @endif
        </nav>
    </header>

    <main class="public-main">
        @yield('content')
    </main>

    <footer class="public-footer">
        killsineve.online · public mirror
    </footer>
</body>
</html>

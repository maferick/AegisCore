{{--
    AegisCore landing page.

    Self-contained: no external fonts, no CDN, no Vite build step. When we
    grow real assets (Filament theming, Horizon-style dashboards), this
    moves into a proper layout + Vite bundle — but for phase 1 this is
    the minimum viable "front door" that looks intentional.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>AegisCore — alliance intelligence</title>
    <link rel="icon" href="data:,">
    <style>
        :root {
            --bg: #0a0a0b;
            --bg-elev: #111113;
            --border: #26262b;
            --border-hot: #3a3a42;
            --text: #e5e5e7;
            --muted: #7a7a82;
            /* EVE HUD palette.
             * --accent: cyan — primary UI accent (friendlies, selection, "go").
             * --gold:   amber — "your alliance / your ship / status".
             * --danger: red  — hostile signal / alert. Reserved, unused here. */
            --accent: #4fd0d0;
            --accent-dim: #3aa8a8;
            --gold: #e5a900;
            --danger: #ff3838;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }

        body {
            font: 15px/1.55 -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto,
                  'Helvetica Neue', Arial, sans-serif;
            background:
                radial-gradient(ellipse at 15% -10%, rgba(79, 208, 208, 0.10) 0%, transparent 45%),
                radial-gradient(ellipse at 85% 110%, rgba(229, 169, 0, 0.05) 0%, transparent 45%),
                var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
        }

        .mono {
            font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, 'Liberation Mono', monospace;
        }

        /* ---------- Header ---------- */
        header {
            padding: 1.25rem 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .logotype {
            font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
            font-size: 0.85rem;
            letter-spacing: 0.22em;
            text-transform: uppercase;
            color: var(--text);
        }
        .logotype .dot { color: var(--accent); }

        .env-badge {
            font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
            font-size: 0.7rem;
            color: var(--gold);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            padding: 0.3rem 0.55rem;
            border: 1px solid rgba(229, 169, 0, 0.35);
            border-radius: 3px;
        }

        /* ---------- Hero ---------- */
        main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 2rem;
        }
        .hero {
            max-width: 720px;
            width: 100%;
        }
        h1 {
            font-size: clamp(2.25rem, 5.5vw, 3.75rem);
            font-weight: 700;
            letter-spacing: -0.035em;
            line-height: 1.05;
            margin-bottom: 1.1rem;
        }
        h1 .accent { color: var(--accent); }

        .tagline {
            font-size: 1.1rem;
            color: var(--muted);
            margin-bottom: 2.25rem;
            max-width: 580px;
        }

        /* ---------- Pillar grid (mirrors app/app/Domains/) ---------- */
        .pillars {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 0.6rem;
            margin-bottom: 2.5rem;
        }
        .pillar {
            padding: 0.95rem 1rem;
            background: var(--bg-elev);
            border: 1px solid var(--border);
            border-radius: 4px;
            transition: border-color 0.15s;
        }
        .pillar:hover { border-color: var(--border-hot); }
        .pillar-name {
            font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
            font-size: 0.7rem;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 0.12em;
            margin-bottom: 0.4rem;
        }
        .pillar-desc {
            font-size: 0.83rem;
            color: var(--muted);
            line-height: 1.45;
        }

        /* ---------- Actions ---------- */
        .actions {
            display: flex;
            gap: 0.6rem;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.65rem 1.25rem;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            border: 1px solid var(--border);
            color: var(--text);
            background: var(--bg-elev);
            transition: border-color 0.15s, color 0.15s, background 0.15s;
        }
        .btn:hover { border-color: var(--accent); color: var(--accent); }
        .btn-primary {
            background: var(--accent);
            color: #0a0a0b;
            border-color: var(--accent);
            font-weight: 600;
        }
        .btn-primary:hover {
            background: var(--accent-dim);
            border-color: var(--accent-dim);
            color: #0a0a0b;
        }

        /* ---------- Footer ---------- */
        footer {
            padding: 1rem 2rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
            font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
            font-size: 0.72rem;
            color: var(--muted);
            letter-spacing: 0.05em;
        }

        @media (max-width: 480px) {
            header, main, footer { padding-left: 1.25rem; padding-right: 1.25rem; }
        }
    </style>
</head>
<body>
    <header>
        <div class="logotype">AegisCore<span class="dot">.</span></div>
        <div class="env-badge">{{ config('app.env') }}</div>
    </header>

    <main>
        <div class="hero">
            <h1>Alliance intel.<br><span class="accent">For New Eden.</span></h1>
            <p class="tagline">
                Spy detection, buyall doctrines, killmail analysis, and battle theaters —
                forged from New Eden lore, coalition war stories, and /r/Eve AARs, meta, and salt.
            </p>

            <div class="pillars">
                <div class="pillar">
                    <div class="pillar-name">Spy detection</div>
                    <div class="pillar-desc">Lateral-movement signals across ally corps.</div>
                </div>
                <div class="pillar">
                    <div class="pillar-name">Buyall doctrines</div>
                    <div class="pillar-desc">Fit coverage and stock across staging hubs.</div>
                </div>
                <div class="pillar">
                    <div class="pillar-name">Killmails + theaters</div>
                    <div class="pillar-desc">Time-series kill rate + combatant graph.</div>
                </div>
                <div class="pillar">
                    <div class="pillar-name">Users + characters</div>
                    <div class="pillar-desc">EVE SSO + alliance RBAC.</div>
                </div>
            </div>

            <div class="actions">
                <a href="/admin" class="btn btn-primary">Admin &rarr;</a>
                <a href="/horizon" class="btn">Horizon</a>
                <a href="https://github.com/maferick/AegisCore" class="btn" rel="noopener">GitHub</a>
            </div>
        </div>
    </main>

    <footer>
        <span>v0.1.0 &middot; phase 1</span>
        <span>{{ config('app.name', 'AegisCore') }}</span>
    </footer>
</body>
</html>

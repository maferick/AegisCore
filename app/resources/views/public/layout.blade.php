<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Battles') — AegisCore</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <style>
        :root {
            --bg: #0a0a0b;
            --panel: rgba(17,17,19,0.6);
            --border: #26262b;
            --muted: #7a7a82;
            --text: #e5e5e7;
            --cyan: #4fd0d0;
            --amber: #e5a900;
            --red: #ff3838;
            --green: #4ade80;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background:
                radial-gradient(rgba(79,208,208,0.045) 1px, transparent 1.5px) 0 0 / 28px 28px,
                radial-gradient(ellipse at 15% -10%, rgba(79,208,208,0.10) 0%, transparent 45%),
                radial-gradient(ellipse at 85% 110%, rgba(229,169,0,0.05) 0%, transparent 45%),
                #0a0a0b;
            background-attachment: fixed;
            color: var(--text);
            font-family: Inter, system-ui, -apple-system, sans-serif;
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
</head>
<body>
    <header class="public-topbar">
        <a class="brand" href="/">AegisCore</a>
        <nav>
            <a href="/">Home</a>
            <a href="{{ route('public.battles.index') }}">Battles</a>
            @auth
                <a href="/portal">Portal</a>
            @else
                <a href="/auth/eve">Sign in</a>
            @endauth
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

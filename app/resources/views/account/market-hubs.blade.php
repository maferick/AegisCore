{{--
    /account/market-hubs — multi-hub list + default-hub preference.

    Chrome + CSS intentionally mirror /account/settings so the two
    donor surfaces feel like the same product. Interactive body is
    delegated to the `account.market-hubs` Livewire component.

    ADR-0005 § Follow-ups #1 — registration + revoke stay on
    /account/settings; this page adds the "richer multi-hub list
    view + set default" the ADR reserved for a dedicated route.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Market hubs — AegisCore</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    @livewireStyles
    <style>
        :root {
            --bg: #0a0a0b;
            --bg-elev: #111113;
            --border: #26262b;
            --border-hot: #3a3a42;
            --text: #e5e5e7;
            --muted: #7a7a82;
            --accent: #4fd0d0;
            --accent-dim: #3aa8a8;
            --gold: #e5a900;
            --danger: #ff3838;
            --success: #4ade80;
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
            background-attachment: fixed, fixed, scroll;
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .mono { font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace; }

        header {
            padding: 1.25rem 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        header a { color: var(--accent); text-decoration: none; }
        header a:hover { text-decoration: underline; }
        .logo-lockup {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            text-decoration: none !important;
            color: var(--text) !important;
            transition: opacity 0.15s;
        }
        .logo-lockup:hover { opacity: 0.85; }
        .logo-mark {
            width: 26px;
            height: 26px;
            flex-shrink: 0;
            transition: filter 0.2s;
        }
        .logo-lockup:hover .logo-mark {
            filter: drop-shadow(0 0 6px rgba(79, 208, 208, 0.45));
        }
        .logotype {
            font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
            font-size: 0.92rem;
            font-weight: 600;
            letter-spacing: 0.08em;
        }

        .page-layout { display: flex; gap: 0; flex: 1; }
        .side-nav {
            width: 220px;
            flex-shrink: 0;
            padding: 1.5rem 1rem;
            border-right: 1px solid var(--border);
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }
        .side-nav-label {
            font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
            font-size: 0.6rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--muted);
            margin-bottom: 0.5rem;
            padding-left: 0.75rem;
        }
        .side-nav a {
            display: block;
            padding: 0.5rem 0.75rem;
            border-radius: 4px;
            font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
            font-size: 0.78rem;
            color: var(--text);
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }
        .side-nav a:hover {
            background: rgba(79, 208, 208, 0.08);
            color: var(--accent);
        }
        .side-nav a.nav-active {
            background: rgba(79, 208, 208, 0.12);
            color: var(--accent);
        }
        .side-nav-divider {
            height: 1px;
            background: var(--border);
            margin: 0.75rem 0;
        }
        .page-content { flex: 1; min-width: 0; }
        @media (max-width: 768px) {
            .side-nav { display: none; }
        }

        main {
            flex: 1;
            max-width: 1100px;
            margin: 0 auto;
            padding: 2rem;
            width: 100%;
        }

        h1 { font-size: 1.75rem; font-weight: 600; margin-bottom: 0.25rem; }
        h2 {
            font-size: 1.15rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            color: var(--accent);
        }
        .subtitle { color: var(--muted); margin-bottom: 2rem; }

        .card {
            background: var(--bg-elev);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .card.donor {
            border-color: var(--gold);
            background: linear-gradient(135deg, rgba(229, 169, 0, 0.05), transparent 40%), var(--bg-elev);
        }

        .flash {
            padding: 0.85rem 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            border: 1px solid;
        }
        .flash.error { border-color: var(--danger); background: rgba(255, 56, 56, 0.08); }
        .flash.success { border-color: var(--success); background: rgba(74, 222, 128, 0.08); }

        .badge {
            display: inline-block;
            padding: 0.1rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
            border: 1px solid var(--border-hot);
        }
        .badge.ok     { color: var(--success); border-color: var(--success); }
        .badge.warn   { color: var(--gold);    border-color: var(--gold); }
        .badge.bad    { color: var(--danger);  border-color: var(--danger); }
        .badge.muted  { color: var(--muted); }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.55rem 1rem;
            border-radius: 6px;
            background: var(--accent);
            color: var(--bg);
            text-decoration: none;
            font-weight: 600;
            border: 0;
            cursor: pointer;
            font: inherit;
            font-weight: 600;
        }
        .btn:hover { background: var(--accent-dim); }
        .btn:disabled { opacity: 0.6; cursor: wait; }
        .btn.secondary {
            background: transparent;
            color: var(--text);
            border: 1px solid var(--border-hot);
        }
        .btn.secondary:hover { border-color: var(--accent); color: var(--accent); }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        th, td {
            padding: 0.5rem 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        th {
            color: var(--muted);
            font-weight: 500;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .empty {
            color: var(--muted);
            font-style: italic;
            text-align: center;
            padding: 1.5rem;
        }
    </style>
</head>
<body>
<header>
    <a href="{{ url('/') }}" class="logo-lockup" aria-label="AegisCore home">
        <svg class="logo-mark" viewBox="0 0 64 64" fill="none" role="img" aria-hidden="true">
            <path d="M32 4 L56 18 L56 46 L32 60 L8 46 L8 18 Z"
                  stroke="#4fd0d0" stroke-width="2.5" stroke-linejoin="round"
                  fill="rgba(10, 10, 11, 0.7)"/>
            <path d="M32 18 L44 25 L44 39 L32 46 L20 39 L20 25 Z"
                  stroke="#e5a900" stroke-width="1" stroke-linejoin="round"
                  opacity="0.7" fill="none"/>
            <circle cx="32" cy="32" r="2.75" fill="#4fd0d0"/>
        </svg>
        <span class="logotype">AegisCore</span>
    </a>
    <form method="POST" action="{{ route('auth.logout') }}" style="margin: 0;">
        @csrf
        <button class="btn secondary" type="submit">Sign out</button>
    </form>
</header>

<div class="page-layout">
    <nav class="side-nav" aria-label="Account navigation">
        <div class="side-nav-label">Navigate</div>
        <a href="/">Home</a>
        <a href="/portal">My Character</a>
        <div class="side-nav-divider"></div>
        <div class="side-nav-label">Account</div>
        <a href="{{ route('filament.portal.pages.account-settings') }}">Settings</a>
        <a href="{{ route('account.market-hubs') }}" class="nav-active">Market Hubs</a>
    </nav>

    <main class="page-content">
        <h1>Market hubs</h1>
        <p class="subtitle">All hubs you're entitled to view, plus your pinned comparison default.</p>

        @if (session('success'))
            <div class="flash success">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="flash error">{{ session('error') }}</div>
        @endif

        @livewire('account.market-hubs')
    </main>
</div>
@livewireScripts
</body>
</html>

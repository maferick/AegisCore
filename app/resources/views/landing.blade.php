{{--
    AegisCore landing page.

    Self-contained: no external fonts, no CDN, no Vite build step. When we
    grow real assets (Filament theming, Horizon-style dashboards), this
    moves into a proper layout + Vite bundle — but for phase 1 this is
    the minimum viable "front door" that looks intentional.
--}}
@php
    /** @var \App\Models\User|null $authUser */
    $authUser = auth()->user();
    $primaryCharacter = $authUser?->characters()->first();
    $isAdmin = $authUser
        ? $authUser->canAccessPanel(\Filament\Facades\Filament::getPanel('admin'))
        : false;
    $ssoConfigured = \App\Services\Eve\Sso\EveSsoClient::isConfigured();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>AegisCore — alliance intelligence</title>
    {{-- SVG favicon: scales cleanly from 16px tab icons up. The file is
         the same brand mark we lock up in the header below. --}}
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
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

        /* ---------- Themed backdrop ----------
         * Four stacked layers; top → bottom, everything above the base
         * colour is pinned (`fixed`) so the backdrop stays put while
         * content scrolls — the hero reads as if it's laid over a HUD
         * rather than pasted onto a coloured sheet.
         *
         *   1. Constellation / intel-network map  — pseudo-random scatter
         *      of "system" dots joined by thin cyan traces (jump routes
         *      + intel links), plus a few gold "flagged target" dots
         *      ringed with faint reticles. Evokes the EVE universe map
         *      and the product pillars (spy detection, battle theaters,
         *      alliance intel) without reusing the hex-shield logo.
         *   2. Sensor dot matrix (28px grid)      — faint cyan dots on
         *      a fixed lattice. Reads as "HUD texture" underneath the
         *      star map, not noise.
         *   3. Cyan atmospheric glow (top-L)      — existing.
         *   4. Gold atmospheric glow (bot-R)      — existing.
         *
         * All stroke/fill opacities are baked into the SVG so we don't
         * need a pseudo-element with `opacity` — keeps `body` one
         * stacking context, header/main/footer render on top without
         * any z-index plumbing. `preserveAspectRatio='xMidYMid slice'`
         * on the SVG + `background-size: cover` means the map fills
         * any aspect ratio cleanly; edges crop rather than distort. */
        body {
            font: 15px/1.55 -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto,
                  'Helvetica Neue', Arial, sans-serif;
            background:
                url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1600 900' fill='none' preserveAspectRatio='xMidYMid slice'><g stroke='%234fd0d0' stroke-width='0.7' stroke-opacity='0.18' stroke-linecap='round'><line x1='140' y1='180' x2='300' y2='120'/><line x1='140' y1='180' x2='220' y2='300'/><line x1='300' y1='120' x2='460' y2='210'/><line x1='300' y1='120' x2='220' y2='300'/><line x1='460' y1='210' x2='380' y2='340'/><line x1='460' y1='210' x2='560' y2='300'/><line x1='220' y1='300' x2='380' y2='340'/><line x1='380' y1='340' x2='560' y2='300'/><line x1='560' y1='300' x2='720' y2='200'/><line x1='560' y1='300' x2='640' y2='440'/><line x1='720' y1='200' x2='880' y2='280'/><line x1='880' y1='280' x2='1040' y2='180'/><line x1='880' y1='280' x2='820' y2='480'/><line x1='1040' y1='180' x2='1200' y2='260'/><line x1='1040' y1='180' x2='1000' y2='420'/><line x1='1200' y1='260' x2='1360' y2='200'/><line x1='1200' y1='260' x2='1000' y2='420'/><line x1='1360' y1='200' x2='1480' y2='340'/><line x1='1480' y1='340' x2='1320' y2='460'/><line x1='640' y1='440' x2='820' y2='480'/><line x1='820' y1='480' x2='1000' y2='420'/><line x1='1000' y1='420' x2='1160' y2='540'/><line x1='1160' y1='540' x2='1320' y2='460'/><line x1='1160' y1='540' x2='1440' y2='620'/><line x1='1320' y1='460' x2='1440' y2='620'/><line x1='220' y1='300' x2='220' y2='480'/><line x1='640' y1='440' x2='580' y2='620'/><line x1='580' y1='620' x2='760' y2='660'/><line x1='760' y1='660' x2='940' y2='720'/><line x1='940' y1='720' x2='1100' y2='780'/><line x1='1100' y1='780' x2='1280' y2='720'/><line x1='1280' y1='720' x2='1440' y2='620'/><line x1='400' y1='540' x2='580' y2='620'/><line x1='400' y1='540' x2='220' y2='480'/><line x1='220' y1='480' x2='300' y2='680'/><line x1='300' y1='680' x2='480' y2='760'/><line x1='480' y1='760' x2='660' y2='820'/><line x1='660' y1='820' x2='940' y2='720'/></g><g stroke='%234fd0d0' stroke-width='0.5' stroke-opacity='0.12' stroke-dasharray='3 5'><line x1='140' y1='180' x2='560' y2='300'/><line x1='720' y1='200' x2='1040' y2='180'/><line x1='220' y1='480' x2='940' y2='720'/><line x1='1000' y1='420' x2='1280' y2='720'/></g><g fill='%234fd0d0' fill-opacity='0.4'><circle cx='140' cy='180' r='1.5'/><circle cx='300' cy='120' r='1.5'/><circle cx='460' cy='210' r='1.5'/><circle cx='220' cy='300' r='1.5'/><circle cx='380' cy='340' r='1.5'/><circle cx='720' cy='200' r='1.5'/><circle cx='880' cy='280' r='1.5'/><circle cx='1040' cy='180' r='1.5'/><circle cx='1200' cy='260' r='1.5'/><circle cx='1360' cy='200' r='1.5'/><circle cx='1480' cy='340' r='1.5'/><circle cx='640' cy='440' r='1.5'/><circle cx='1000' cy='420' r='1.5'/><circle cx='1160' cy='540' r='1.5'/><circle cx='1320' cy='460' r='1.5'/><circle cx='220' cy='480' r='1.5'/><circle cx='400' cy='540' r='1.5'/><circle cx='580' cy='620' r='1.5'/><circle cx='760' cy='660' r='1.5'/><circle cx='940' cy='720' r='1.5'/><circle cx='1100' cy='780' r='1.5'/><circle cx='300' cy='680' r='1.5'/><circle cx='480' cy='760' r='1.5'/><circle cx='660' cy='820' r='1.5'/><circle cx='1440' cy='620' r='1.5'/></g><g fill='%23e5a900' fill-opacity='0.5'><circle cx='560' cy='300' r='2.5'/><circle cx='820' cy='480' r='2.5'/><circle cx='1280' cy='720' r='2.5'/></g><g stroke='%23e5a900' stroke-width='0.5' stroke-opacity='0.32' fill='none'><circle cx='560' cy='300' r='7'/><circle cx='820' cy='480' r='8'/><circle cx='1280' cy='720' r='7'/></g></svg>")
                    center center / cover no-repeat,
                radial-gradient(rgba(79, 208, 208, 0.045) 1px, transparent 1.5px)
                    0 0 / 28px 28px,
                radial-gradient(ellipse at 15% -10%, rgba(79, 208, 208, 0.10) 0%, transparent 45%),
                radial-gradient(ellipse at 85% 110%, rgba(229, 169, 0, 0.05) 0%, transparent 45%),
                var(--bg);
            background-attachment: fixed, fixed, fixed, fixed, scroll;
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
            gap: 1rem;
        }
        /* Logo lockup: hex shield SVG + wordmark, links back to home.
         * The SVG mark mirrors public/favicon.svg so the brand reads the
         * same in the browser tab and on the page itself. */
        .logo-lockup {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            text-decoration: none;
            color: var(--text);
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
            font-size: 0.85rem;
            letter-spacing: 0.22em;
            text-transform: uppercase;
            color: var(--text);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

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

        /* ---------- Authenticated user badge ---------- */
        /* Sits in the header-right slot. Portrait + name + sign-out, in
         * the same visual register as env-badge so it doesn't dominate
         * the marketing page. Pill shape distinguishes "this is you" from
         * the squared "this is metadata" env-badge next to it. */
        .user-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.25rem 0.55rem 0.25rem 0.3rem;
            background: var(--bg-elev);
            border: 1px solid var(--border);
            border-radius: 999px;
        }
        .user-portrait {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            border: 1px solid rgba(229, 169, 0, 0.45);
            background: #000;
            object-fit: cover;
            flex-shrink: 0;
        }
        .user-portrait--placeholder {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--gold);
            font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .user-name {
            font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
            font-size: 0.75rem;
            color: var(--text);
            letter-spacing: 0.05em;
            max-width: 14ch;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .user-signout {
            background: none;
            border: none;
            padding: 0.15rem 0.25rem;
            margin-left: 0.15rem;
            font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--muted);
            cursor: pointer;
            transition: color 0.15s;
        }
        .user-signout:hover { color: var(--danger); }

        /* ---------- Authenticated nav strip ----------
         * Thin horizontal nav between header and hero, visible only
         * when logged in. Links to the user-facing surfaces available
         * right now. Admins get the Admin entry; donors / admins get
         * anything donor-gated as it lands.
         *
         * Keep the nav SHORT: only link things that exist. Ghost /
         * coming-soon entries send non-paying users into dead ends and
         * make the paying-customer feature slate feel hollow. When a
         * page goes live, add it here. */
        .nav-strip {
            border-bottom: 1px solid var(--border);
            padding: 0.55rem 2rem;
            background: rgba(10, 10, 11, 0.65);
            backdrop-filter: blur(6px);
        }
        .nav-strip-inner {
            display: flex;
            gap: 0.35rem;
            flex-wrap: wrap;
            align-items: center;
        }
        .nav-link {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.85rem;
            border-radius: 3px;
            font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--muted);
            text-decoration: none;
            border: 1px solid transparent;
            transition: color 0.15s, border-color 0.15s, background 0.15s;
        }
        .nav-link:hover {
            color: var(--accent);
            border-color: rgba(79, 208, 208, 0.35);
            background: rgba(79, 208, 208, 0.04);
        }
        .nav-link--admin {
            color: var(--gold);
        }
        .nav-link--admin:hover {
            color: var(--gold);
            border-color: rgba(229, 169, 0, 0.45);
            background: rgba(229, 169, 0, 0.06);
        }
        /* Guest login entry. Same gold accent as the old .btn-eve
         * button it replaces — EVE's in-game "your alliance / your
         * ship" colour — plus a slightly heavier weight + letter
         * spacing so it reads as the primary call to action from a
         * cold open, while still visually sitting in the same slot
         * as the Account link a logged-in user sees. */
        .nav-link--login {
            color: var(--gold);
            border-color: rgba(229, 169, 0, 0.35);
            background: rgba(229, 169, 0, 0.04);
        }
        .nav-link--login:hover {
            color: var(--gold);
            border-color: var(--gold);
            background: rgba(229, 169, 0, 0.09);
        }

        /* ---------- Hero ---------- */
        main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 2rem;
        }
        /* Hero is a two-column layout on desktop: large brand mark on the
         * left, the marketing copy + pillars + CTAs on the right. The mark
         * is decorative — `aria-hidden` on the SVG keeps screen readers
         * from announcing it twice (the small one in the header is the
         * accessible identity). On narrow viewports the mark moves above
         * the copy and shrinks; below 480px it hides entirely so the
         * hero text can claim the full width. */
        .hero {
            display: flex;
            align-items: center;
            gap: 2.5rem;
            max-width: 960px;
            width: 100%;
        }
        .hero-mark {
            width: clamp(140px, 18vw, 220px);
            height: clamp(140px, 18vw, 220px);
            flex-shrink: 0;
            /* Subtle drift-glow so the mark reads as "intel signal" rather
             * than a flat decal. Drop-shadow on the cyan stroke alone — the
             * gold reticle stays sharp. */
            filter: drop-shadow(0 0 14px rgba(79, 208, 208, 0.18));
            animation: hero-mark-pulse 5s ease-in-out infinite;
        }
        @keyframes hero-mark-pulse {
            0%, 100% { filter: drop-shadow(0 0 14px rgba(79, 208, 208, 0.18)); }
            50%      { filter: drop-shadow(0 0 22px rgba(79, 208, 208, 0.32)); }
        }
        @media (prefers-reduced-motion: reduce) {
            .hero-mark { animation: none; }
        }
        .hero-body {
            flex: 1;
            min-width: 0;
        }
        @media (max-width: 720px) {
            .hero {
                flex-direction: column;
                align-items: flex-start;
                gap: 1.5rem;
            }
            .hero-mark { width: 120px; height: 120px; }
        }
        @media (max-width: 480px) {
            .hero-mark { display: none; }
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


        /* ---------- Kill banner (scrolling marquee) ---------- */
        .kill-banner {
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            background: rgba(17, 17, 19, 0.6);
            overflow: hidden;
            position: relative;
            white-space: nowrap;
        }
        .kill-banner-label {
            position: absolute;
            left: 0; top: 0; bottom: 0;
            z-index: 2;
            display: flex;
            align-items: center;
            padding: 0 1rem;
            background: linear-gradient(90deg, rgba(10,10,11,0.95) 70%, transparent);
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.6rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--accent);
        }
        .kill-banner-track {
            display: inline-flex;
            animation: scroll-left 60s linear infinite;
            padding: 0.6rem 0;
        }
        .kill-banner-track:hover { animation-play-state: paused; }
        @keyframes scroll-left {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
        .kill-card {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-right: 2rem;
            flex-shrink: 0;
        }
        .kill-card-ship {
            width: 36px; height: 36px;
            border-radius: 4px;
            border: 1px solid rgba(79, 208, 208, 0.2);
        }
        .kill-card-info {
            display: flex;
            flex-direction: column;
            gap: 0.1rem;
        }
        .kill-card-ship-name {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text);
        }
        .kill-card-victim {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.65rem;
            color: var(--muted);
        }
        .kill-card-value {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--gold);
            margin-left: auto;
            white-space: nowrap;
        }
        .kill-card-sep {
            width: 1px;
            height: 24px;
            background: var(--border);
            margin-right: 2rem;
            flex-shrink: 0;
        }

        /* ---------- Stats ticker ---------- */
        .stats-ticker {
            margin-top: 2.5rem;
            padding: 1.2rem 2rem;
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            background: rgba(17, 17, 19, 0.5);
            backdrop-filter: blur(6px);
        }
        .stats-ticker-inner {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 2rem;
            flex-wrap: wrap;
        }
        .ticker-stat {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.2rem;
        }
        .ticker-value {
            font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--text);
            letter-spacing: 0.02em;
        }
        .ticker-label {
            font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
            font-size: 0.6rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--muted);
        }
        .ticker-sep {
            width: 1px;
            height: 2rem;
            background: var(--border);
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
            .user-name { max-width: 8ch; }
        }
    </style>
</head>
<body>
    <header>
        {{--
            Logo lockup. The hex shield SVG below is the same mark as
            public/favicon.svg — keep them in lockstep when iterating.
            Anchor → home so logo-click navigates back from any future
            sub-page.
        --}}
        <a href="{{ url('/') }}" class="logo-lockup" aria-label="AegisCore home">
            <svg class="logo-mark" viewBox="0 0 64 64" fill="none" role="img" aria-hidden="true">
                <path d="M32 4 L56 18 L56 46 L32 60 L8 46 L8 18 Z"
                      stroke="#4fd0d0" stroke-width="2.5" stroke-linejoin="round"
                      fill="rgba(10, 10, 11, 0.6)"/>
                <path d="M32 18 L44 25 L44 39 L32 46 L20 39 L20 25 Z"
                      stroke="#e5a900" stroke-width="1.25" stroke-linejoin="round"
                      opacity="0.7" fill="none"/>
                <g stroke="#e5a900" stroke-width="1.75" stroke-linecap="round">
                    <line x1="32" y1="22" x2="32" y2="27"/>
                    <line x1="32" y1="37" x2="32" y2="42"/>
                    <line x1="22" y1="32" x2="27" y2="32"/>
                    <line x1="37" y1="32" x2="42" y2="32"/>
                </g>
                <circle cx="32" cy="32" r="2.75" fill="#4fd0d0"/>
            </svg>
            <span class="logotype">AegisCore</span>
        </a>

        <div class="header-right">
            @if ($authUser)
                {{--
                    Logged-in identity badge. Portrait + character name +
                    inline sign-out. We hit images.evetech.net directly —
                    CCP serves portraits unauth'd over a CDN, so no token
                    needed and no proxying through the app. `referrerpolicy`
                    keeps our hostname out of CCP's logs on every render.
                --}}
                <div class="user-badge">
                    @if ($primaryCharacter)
                        <img class="user-portrait"
                             src="https://images.evetech.net/characters/{{ $primaryCharacter->character_id }}/portrait?size=64"
                             alt="{{ $authUser->name }}'s portrait"
                             width="26" height="26"
                             loading="lazy" referrerpolicy="no-referrer">
                    @else
                        {{-- Operator-seeded account: no EVE character to
                             pull a portrait for. Show the first letter of
                             the user name in the gold accent so the badge
                             still has a visual anchor. --}}
                        <span class="user-portrait user-portrait--placeholder">
                            {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($authUser->name, 0, 1)) }}
                        </span>
                    @endif
                    <span class="user-name" title="{{ $authUser->name }}">{{ $authUser->name }}</span>
                    <form method="POST" action="{{ route('auth.logout') }}" style="display: inline;">
                        @csrf
                        <button type="submit" class="user-signout" title="Sign out">Sign out</button>
                    </form>
                </div>
            @endif
            <div class="env-badge">{{ config('app.env') }}</div>
        </div>
    </header>

    {{--
        Primary nav strip. Renders for:
          - Authenticated users → Account (+ Admin for admins).
          - Guests (when SSO is configured) → single "Log in with EVE
            Online" entry in the same slot the Account link takes for
            logged-in users. Keeps the top-of-page entry point in one
            consistent location regardless of auth state, instead of
            burying login in the hero actions below the fold.
        Only live destinations go here — see .nav-strip comment above.
    --}}
    @if ($authUser || $ssoConfigured)
        <nav class="nav-strip" aria-label="Primary navigation">
            <div class="nav-strip-inner">
                @if ($authUser)
                    <a href="/portal" class="nav-link">My Character</a>
                    @if ($isAdmin)
                        <a href="/admin" class="nav-link nav-link--admin">Admin</a>
                    @endif
                @else
                    <a href="{{ route('auth.eve.redirect') }}" class="nav-link nav-link--login">
                        Log in with EVE Online
                    </a>
                @endif
            </div>
        </nav>
    @endif

    <main>
        <div class="hero">
            {{--
                Hero brand mark — large decorative copy of the same shield
                that lives in the header + favicon. Keep this SVG in lockstep
                with public/favicon.svg + the header lockup; aria-hidden so
                AT users aren't told about the logo three times on one page
                (header lockup is the accessible identity).
            --}}
            <svg class="hero-mark" viewBox="0 0 64 64" fill="none" role="presentation" aria-hidden="true">
                <path d="M32 4 L56 18 L56 46 L32 60 L8 46 L8 18 Z"
                      stroke="#4fd0d0" stroke-width="2" stroke-linejoin="round"
                      fill="rgba(10, 10, 11, 0.7)"/>
                <path d="M32 18 L44 25 L44 39 L32 46 L20 39 L20 25 Z"
                      stroke="#e5a900" stroke-width="1" stroke-linejoin="round"
                      opacity="0.7" fill="none"/>
                <g stroke="#e5a900" stroke-width="1.5" stroke-linecap="round">
                    <line x1="32" y1="22" x2="32" y2="27"/>
                    <line x1="32" y1="37" x2="32" y2="42"/>
                    <line x1="22" y1="32" x2="27" y2="32"/>
                    <line x1="37" y1="32" x2="42" y2="32"/>
                </g>
                <circle cx="32" cy="32" r="2.75" fill="#4fd0d0"/>
            </svg>

            <div class="hero-body">
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

            {{--
                Primary CTAs live in the top nav strip now (Account for
                logged-in users, "Log in with EVE Online" for guests).
                Keeping the hero body content-only means the call to
                action sits above the fold in the nav rather than
                below the pillars — one consistent entry point.
            --}}
            </div>{{-- /.hero-body --}}
        </div>

    </main>

    @php
        $kmTotal = \Illuminate\Support\Facades\DB::table('killmails')->count();
        $kmEnriched = $kmTotal > 0 ? \Illuminate\Support\Facades\DB::table('killmails')->whereNotNull('enriched_at')->count() : 0;
        $kmLast24h = $kmTotal > 0 ? \Illuminate\Support\Facades\DB::table('killmails')->where('ingested_at', '>=', now()->subDay())->count() : 0;

        // Top kills from last 24h for the scrolling banner.
        $topKills = $kmTotal > 0
            ? \Illuminate\Support\Facades\DB::table('killmails')
                ->where('killed_at', '>=', now()->subDay())
                ->whereNotNull('enriched_at')
                ->where('total_value', '>', 0)
                ->orderByDesc('total_value')
                ->limit(20)
                ->get(['killmail_id', 'victim_character_id', 'victim_ship_type_id', 'victim_ship_type_name', 'total_value', 'killed_at'])
            : collect();

        // Resolve victim names for the banner.
        $bannerVictimIds = $topKills->pluck('victim_character_id')->filter()->unique()->values()->all();
        $bannerNames = $bannerVictimIds
            ? \Illuminate\Support\Facades\DB::table('esi_entity_names')
                ->whereIn('entity_id', $bannerVictimIds)
                ->pluck('name', 'entity_id')
            : collect();

        $formatBannerIsk = function (float $v): string {
            if ($v >= 1e12) return number_format($v / 1e12, 1) . 'T';
            if ($v >= 1e9)  return number_format($v / 1e9, 1) . 'B';
            if ($v >= 1e6)  return number_format($v / 1e6, 1) . 'M';
            if ($v >= 1e3)  return number_format($v / 1e3, 0) . 'K';
            return number_format($v, 0);
        };
    @endphp

    @if($topKills->isNotEmpty())
    <div class="kill-banner">
        <div class="kill-banner-label">Top Kills 24h</div>
        <div class="kill-banner-track" style="padding-left: 120px;">
            {{-- Duplicate the list for seamless infinite scroll --}}
            @for($loop_i = 0; $loop_i < 2; $loop_i++)
                @foreach($topKills as $tk)
                    <div class="kill-card">
                        <img class="kill-card-ship"
                             src="https://images.evetech.net/types/{{ $tk->victim_ship_type_id }}/render?size=64"
                             alt="{{ $tk->victim_ship_type_name ?? '' }}"
                             referrerpolicy="no-referrer"
                             loading="lazy">
                        <div class="kill-card-info">
                            <span class="kill-card-ship-name">{{ $tk->victim_ship_type_name ?? 'Unknown' }}</span>
                            <span class="kill-card-victim">{{ $bannerNames[$tk->victim_character_id] ?? 'Unknown pilot' }}</span>
                        </div>
                        <span class="kill-card-value">{{ $formatBannerIsk((float) $tk->total_value) }}</span>
                    </div>
                    <div class="kill-card-sep"></div>
                @endforeach
            @endfor
        </div>
    </div>
    @endif

    @if($kmTotal > 0)
    <div class="stats-ticker">
        <div class="stats-ticker-inner">
            <div class="ticker-stat">
                <span class="ticker-value accent">{{ number_format($kmTotal) }}</span>
                <span class="ticker-label">killmails tracked</span>
            </div>
            <div class="ticker-sep"></div>
            <div class="ticker-stat">
                <span class="ticker-value" style="color: var(--gold)">{{ number_format($kmEnriched) }}</span>
                <span class="ticker-label">enriched</span>
            </div>
            <div class="ticker-sep"></div>
            <div class="ticker-stat">
                <span class="ticker-value">{{ number_format($kmLast24h) }}</span>
                <span class="ticker-label">last 24h</span>
            </div>
        </div>
    </div>
    @endif

    <footer>
        <span>v0.1.0 &middot; phase 1</span>
        <span>{{ config('app.name', 'AegisCore') }}</span>
    </footer>
</body>
</html>

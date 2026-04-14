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
        /* "Log in with EVE" — gold accent, mirrors EVE's in-game "your
         * alliance / your ship" colour. Distinguishes from the cyan
         * Admin CTA so the two primary actions don't look identical. */
        .btn-eve {
            border-color: rgba(229, 169, 0, 0.55);
            color: var(--gold);
        }
        .btn-eve:hover {
            border-color: var(--gold);
            color: var(--gold);
            background: rgba(229, 169, 0, 0.06);
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
                CTAs gated three ways:

                  - Guest, SSO configured → "Log in with EVE Online"
                  - Logged-in admin       → "Admin →"
                  - Logged-in non-admin   → (no primary CTA — they're
                                            already in; landing stays
                                            content-only for them)

                Per the EVE_SSO_ADMIN_CHARACTER_IDS allow-list (see
                ADR-0002 § Admin gate). Operator-seeded accounts (no
                linked character) also count as admins via the bootstrap
                escape hatch in `User::canAccessPanel`. The Admin button
                used to render for everyone, which sent non-admins into
                a 403 page — now it's only visible to people who can
                actually use it.
            --}}
            <div class="actions">
                @if ($authUser)
                    @if ($isAdmin)
                        <a href="/admin" class="btn btn-primary">Admin &rarr;</a>
                    @endif
                @else
                    @if ($ssoConfigured)
                        <a href="{{ route('auth.eve.redirect') }}" class="btn btn-eve">Log in with EVE Online</a>
                    @endif
                @endif
                <a href="https://github.com/maferick/AegisCore" class="btn" rel="noopener">GitHub</a>
            </div>
            </div>{{-- /.hero-body --}}
        </div>
    </main>

    <footer>
        <span>v0.1.0 &middot; phase 1</span>
        <span>{{ config('app.name', 'AegisCore') }}</span>
    </footer>
</body>
</html>

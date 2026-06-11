<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="refresh" content="3;url=/war-report/{{ $conflict }}/me">
    <title>Loading your war effort…</title>
    @php
        $sayings = [
            "Asking the killboard nicely if it remembers you.",
            "Counting how many times you said 'XING UP'.",
            "Checking who you owe ammo to.",
            "Decrypting your local-chat regrets.",
            "Re-reading every titan kill in your name.",
            "Bribing the cache for a faster lookup.",
            "Aligning to the bookmark of truth.",
            "Spinning up the Aegis brain — please warp slow.",
            "Telling the FC you'll be back in a sec.",
            "Counting your shidded shuttles. (privately, with respect.)",
            "Verifying your standings with the loot fairy.",
            "Reticulating splines.",
            "Putting the bloc on a spreadsheet.",
            "Spinning Drake-shaped cargo containers.",
            "Pulling killmails from the warp tunnel.",
            "Reading your DNA fitting out loud.",
            "Re-fitting your Crow for the pings.",
            "Convincing zKill to be polite.",
            "Counting Pandas. (Carefully, they bite.)",
            "Counting bees. (More carefully — they sting.)",
            "Negotiating with timezone tanking.",
            "Asking your alliance leadership for a moment.",
            "Pulling lat/long from EVE Gate ™.",
            "Booping a probe into your battle history.",
            "It's a feature, not a bug. Probably.",
            "Counting structures you mailed to bonus rooms.",
            "Adjusting Goon-to-Frat ratio in real time.",
            "Tallying capacitor crimes.",
            "Asking ESI to tell us a joke.",
            "Loading more cyno frigates.",
            "Squeezing the database for one more row.",
            "Polishing your killboard to a mirror finish.",
            "Aligning to math.",
            "Stop, hammer time.",
            "Buffering — like a Vargur.",
            "Loading… the FC just said 'one minute'.",
            "Eating one (1) cargo container of crystals.",
            "Yes the warp drive is active.",
            "Convincing CCP we're a real boy.",
            "Spinning, like ships in station.",
            "Drake-tier patience required.",
            "Counting what bumped, what tackled, what cried.",
            "Drafting your kill report acceptance speech.",
            "It is Wednesday, my dudes.",
            "Yo dawg I heard you like fleets.",
            "X-up if you can read this.",
            "Sequencing your supercap thirst.",
            "Logging into the imaginary friend chat.",
            "Querying the loot table god.",
            "Sharpening the Naglfar (don't ask).",
            "Recompiling fleet history at warp 4.",
        ];
        $saying = $sayings[array_rand($sayings)];
    @endphp
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
            --hud-cyan: #6dd6ff;
            --hud-gold: #f4c75c;
            --hud-platinum: #d6dbe4;
            --font-head: 'Orbitron','Rajdhani',system-ui,sans-serif;
            --font-body: 'Rajdhani','Inter',system-ui,sans-serif;
            --font-mono: 'Share Tech Mono','JetBrains Mono',ui-monospace,monospace;
        }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                radial-gradient(rgba(109,214,255,0.04) 1px, transparent 1.5px) 0 0 / 28px 28px,
                radial-gradient(ellipse at 50% 50%, rgba(109,214,255,0.10) 0%, transparent 50%),
                var(--bg-deep);
            color: var(--hud-platinum);
            font-family: var(--font-body);
        }
        .panel {
            text-align: center;
            padding: 2.5rem 3rem;
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 12px;
            background: rgba(0,0,0,0.40);
            box-shadow: 0 0 32px {{ $opposing_tint }};
        }
        .spinner {
            margin: 0 auto 1.2rem;
            width: 56px;
            height: 56px;
            border: 4px solid rgba(255,255,255,0.10);
            border-top-color: {{ $opposing_tint }};
            border-radius: 50%;
            animation: aegis-spin 1.1s linear infinite;
        }
        @keyframes aegis-spin {
            to { transform: rotate(360deg); }
        }
        h1 { margin: 0 0 0.5rem 0; font-size: 1.05rem; letter-spacing: 0.04em; font-weight: 700; }
        p  { margin: 0; font-size: 0.78rem; color: #9ca3af; }
        a  { color: #7dd3fc; text-decoration: none; }
    </style>
    @include('partials.aegis-public-bg')
</head>
<body class="aegis-public-bg" data-page="{{ $page_class }}">
    <div class="panel">
        <div class="spinner"></div>
        <h1>Calculating your war effort…</h1>
        <p style="font-style:italic; color:#cbd5e1;">{{ $saying }}</p>
        <p style="margin-top:0.6rem; font-size:0.65rem;">First-load can take ~30s while we crunch ~100k killmails. Subsequent loads are instant.</p>
    </div>
</body>
</html>

{{--
    /account/settings — phase 5a stub.

    Minimum viable donor-facing surface: identity + donor status +
    (donor-gated) market-data CTA + current market token status +
    read-only list of watched structures.

    The Livewire structure-picker + add/remove actions land in the
    next rollout step. This stub is here so the EveSsoController's
    market-flow redirects all land on a real route from day one.

    Uses the same EVE HUD palette as landing.blade.php so the
    donor surface feels like the same product as the marketing page
    and Filament admin.
--}}
@php
    /** @var \App\Models\User $user */
    /** @var \Illuminate\Support\Collection $characters */
    /** @var bool $is_donor */
    /** @var bool $sso_configured */
    /** @var \Illuminate\Support\Collection $market_tokens */
    /** @var \Illuminate\Support\Collection $watched_structures */
    /** @var string|null $market_redirect_url */
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Account settings — AegisCore</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
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

        main {
            flex: 1;
            max-width: 920px;
            margin: 0 auto;
            padding: 2rem;
            width: 100%;
        }

        h1 {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        h2 {
            font-size: 1.15rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            color: var(--accent);
        }
        .subtitle {
            color: var(--muted);
            margin-bottom: 2rem;
        }

        .card {
            background: var(--bg-elev);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .card.donor {
            border-color: var(--gold);
            background:
                linear-gradient(135deg, rgba(229, 169, 0, 0.05), transparent 40%),
                var(--bg-elev);
        }

        .kv {
            display: grid;
            grid-template-columns: 160px 1fr;
            gap: 0.5rem 1rem;
            margin-bottom: 0.25rem;
        }
        .kv-label { color: var(--muted); }

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
        }
        .btn:hover { background: var(--accent-dim); }
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
    <a href="{{ route('home') }}">← AegisCore</a>
    <form method="POST" action="{{ route('auth.logout') }}" style="margin: 0;">
        @csrf
        <button class="btn secondary" type="submit">Sign out</button>
    </form>
</header>

<main>
    <h1>Account settings</h1>
    <p class="subtitle">Signed in as <strong>{{ $user->name }}</strong></p>

    @if (session('success'))
        <div class="flash success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="flash error">{{ session('error') }}</div>
    @endif

    {{-- ---------- Identity ---------- --}}
    <section class="card">
        <h2>Identity</h2>
        <div class="kv">
            <div class="kv-label">Account email</div>
            <div class="mono">{{ $user->email }}</div>
            <div class="kv-label">Donor status</div>
            <div>
                @if ($is_donor)
                    <span class="badge ok">Active</span>
                @else
                    <span class="badge muted">Not currently a donor</span>
                @endif
            </div>
            <div class="kv-label">Linked characters</div>
            <div>
                @forelse ($characters as $c)
                    <div class="mono">{{ $c->name }} <span class="badge muted">#{{ $c->character_id }}</span></div>
                @empty
                    <span class="badge muted">None</span>
                @endforelse
            </div>
        </div>
    </section>

    {{-- ---------- Market data access (donor-gated) ---------- --}}
    @if ($is_donor)
        <section class="card donor">
            <h2>Market data access</h2>
            <p class="subtitle" style="margin-bottom: 1rem;">
                Authorise one of your EVE characters to read market orders
                from Upwell structures where it has docking access. The
                structure picker only surfaces structures your character
                can actually see — ESI enforces the ACL, not us.
            </p>

            @if ($market_tokens->isEmpty())
                @if ($market_redirect_url)
                    <a class="btn" href="{{ $market_redirect_url }}">Authorise market data</a>
                @else
                    <span class="badge warn">EVE SSO is not configured on this deployment.</span>
                @endif
            @else
                <table>
                    <thead>
                    <tr>
                        <th>Character</th>
                        <th>Market scope</th>
                        <th>Access token</th>
                        <th>Expires</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($market_tokens as $t)
                        <tr>
                            <td class="mono">{{ $t['character_name'] }} <span class="badge muted">#{{ $t['character_id'] }}</span></td>
                            <td>
                                @if ($t['has_market_scope'])
                                    <span class="badge ok">granted</span>
                                @else
                                    <span class="badge bad">missing</span>
                                @endif
                            </td>
                            <td>
                                @if ($t['is_fresh'])
                                    <span class="badge ok">fresh</span>
                                @else
                                    <span class="badge warn">stale — next poll refreshes</span>
                                @endif
                            </td>
                            <td class="mono">{{ $t['expires_at']?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td>
                                @if ($market_redirect_url)
                                    <a class="btn secondary" href="{{ $market_redirect_url }}">Re-authorise</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        {{-- ---------- Watched structures (donor-gated) ---------- --}}
        <section class="card">
            <h2>Watched structures</h2>
            <p class="subtitle" style="margin-bottom: 1rem;">
                Structures your authorised character is currently polling for
                market orders. Add / remove support lands in the next update;
                for now contact an admin if you need to change this list.
            </p>
            @if ($watched_structures->isEmpty())
                <div class="empty">No watched structures yet.</div>
            @else
                <table>
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Structure ID</th>
                        <th>Region</th>
                        <th>Last polled</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($watched_structures as $s)
                        <tr>
                            <td>{{ $s['name'] ?? '(unresolved)' }}</td>
                            <td class="mono">{{ $s['location_id'] }}</td>
                            <td class="mono">{{ $s['region_id'] }}</td>
                            <td class="mono">{{ $s['last_polled_at']?->diffForHumans() ?? 'never' }}</td>
                            <td>
                                @if (! $s['enabled'] && $s['disabled_reason'])
                                    <span class="badge bad">{{ $s['disabled_reason'] }}</span>
                                @elseif ($s['enabled'])
                                    <span class="badge ok">enabled</span>
                                @else
                                    <span class="badge warn">disabled</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @else
        <section class="card">
            <h2>Market data access</h2>
            <p class="subtitle">
                Market data access is a donor benefit. Donations fund the
                infrastructure and grant ad-free access plus access to
                select structure markets via your own EVE character's ACLs.
            </p>
            <a class="btn secondary" href="{{ route('home') }}">How to donate</a>
        </section>
    @endif
</main>
</body>
</html>

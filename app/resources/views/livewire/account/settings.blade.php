{{--
    Livewire component view for /account/settings.

    Three sections:
      1. Identity (read-only).
      2. Market data authorisation (donor-gated) — CTA + token status.
      3. Watched structures (donor-gated) — search + add + remove.

    The search form uses wire:submit.prevent; add/remove buttons use
    wire:click. wire:loading.attr=disabled prevents double-click
    during the ESI round-trip.
--}}
@php
    /** @var \App\Models\User|null $user */
    /** @var bool $is_donor */
    /** @var bool $sso_configured */
    /** @var string|null $market_redirect_url */
    /** @var \App\Domains\UsersCharacters\Models\EveMarketToken|null $market_token */
    /** @var \Illuminate\Support\Collection $watched_structures */
@endphp
<div>
    {{-- ---------- Status flashes (wire-reactive) ---------- --}}
    @if ($status)
        <div class="flash success">{{ $status }}</div>
    @endif
    @if ($error)
        <div class="flash error">{{ $error }}</div>
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
                @forelse ($user->characters as $c)
                    <div class="mono">{{ $c->name }} <span class="badge muted">#{{ $c->character_id }}</span></div>
                @empty
                    <span class="badge muted">None</span>
                @endforelse
            </div>
        </div>
    </section>

    @if ($is_donor)
        {{-- ---------- Market data access ---------- --}}
        <section class="card donor">
            <h2>Market data access</h2>
            <p class="subtitle" style="margin-bottom: 1rem;">
                Authorise one of your EVE characters to read market orders
                from Upwell structures where it has docking access. The
                structure picker only surfaces structures your character
                can actually see — ESI enforces the ACL, not us.
            </p>

            @if ($market_token === null)
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
                    <tr>
                        <td class="mono">{{ $market_token->character_name }} <span class="badge muted">#{{ $market_token->character_id }}</span></td>
                        <td>
                            @if ($market_token->hasScope('esi-markets.structure_markets.v1'))
                                <span class="badge ok">granted</span>
                            @else
                                <span class="badge bad">missing</span>
                            @endif
                        </td>
                        <td>
                            @if ($market_token->isAccessTokenFresh())
                                <span class="badge ok">fresh</span>
                            @else
                                <span class="badge warn">stale — will refresh on next use</span>
                            @endif
                        </td>
                        <td class="mono">{{ $market_token->expires_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td>
                            @if ($market_redirect_url)
                                <a class="btn secondary" href="{{ $market_redirect_url }}">Re-authorise</a>
                            @endif
                        </td>
                    </tr>
                    </tbody>
                </table>
            @endif
        </section>

        {{-- ---------- Watched structures ---------- --}}
        <section class="card">
            <h2>Watched structures</h2>
            <p class="subtitle" style="margin-bottom: 1rem;">
                Structures your authorised character is currently polling for
                market orders. Search for a structure by name to add it; ESI
                only returns matches your character has access to.
            </p>

            {{-- Search form — only rendered when a market token exists. --}}
            @if ($market_token !== null)
                <form wire:submit.prevent="search" style="margin-bottom: 1.5rem;">
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="text"
                               wire:model="query"
                               placeholder="Search structures by name (3+ chars)…"
                               style="flex: 1; padding: 0.5rem 0.75rem; background: var(--bg);
                                      border: 1px solid var(--border-hot); border-radius: 6px;
                                      color: var(--text); font: inherit;"
                               required minlength="3">
                        <button class="btn" type="submit" wire:loading.attr="disabled" wire:target="search">
                            <span wire:loading.remove wire:target="search">Search</span>
                            <span wire:loading wire:target="search">Searching…</span>
                        </button>
                    </div>
                </form>

                {{-- Search results --}}
                @if (count($results ?? []) > 0)
                    <table style="margin-bottom: 1.5rem;">
                        <thead>
                        <tr>
                            <th>Structure</th>
                            <th>System</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($results as $r)
                            <tr>
                                <td>{{ $r['name'] }} <span class="badge muted">#{{ $r['structure_id'] }}</span></td>
                                <td>{{ $r['system_name'] }}</td>
                                <td>
                                    <button class="btn secondary"
                                            type="button"
                                            wire:click="addStructure({{ $r['structure_id'] }})"
                                            wire:loading.attr="disabled"
                                            wire:target="addStructure({{ $r['structure_id'] }})">
                                        Watch
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            @endif

            {{-- Currently-watched list --}}
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
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($watched_structures as $s)
                        <tr>
                            <td>{{ $s->name ?? '(unresolved)' }}</td>
                            <td class="mono">{{ $s->location_id }}</td>
                            <td class="mono">{{ $s->region_id }}</td>
                            <td class="mono">{{ $s->last_polled_at?->diffForHumans() ?? 'never' }}</td>
                            <td>
                                @if (! $s->enabled && $s->disabled_reason)
                                    <span class="badge bad">{{ $s->disabled_reason }}</span>
                                @elseif ($s->enabled)
                                    <span class="badge ok">enabled</span>
                                @else
                                    <span class="badge warn">disabled</span>
                                @endif
                            </td>
                            <td>
                                <button class="btn secondary"
                                        type="button"
                                        wire:click="removeStructure({{ $s->id }})"
                                        wire:confirm="Stop watching {{ $s->name ?? $s->location_id }}? Existing order history stays; polling stops."
                                        wire:loading.attr="disabled">
                                    Remove
                                </button>
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
</div>

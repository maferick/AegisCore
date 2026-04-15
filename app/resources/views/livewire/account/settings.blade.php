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
    /** @var bool $is_admin */
    /** @var bool $has_market_access  Either donor or admin — feature gate for the market-data + picker sections. */
    /** @var bool $sso_configured */
    /** @var string|null $market_redirect_url */
    /** @var \App\Domains\UsersCharacters\Models\EveMarketToken|null $market_token */
    /** @var \Illuminate\Support\Collection $watched_structures */
    /** @var array<string, array{owner_id: int, rows: \Illuminate\Support\Collection}> $standings_by_owner */
    /** @var list<string> $standings_token_missing_scopes */
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

    @if ($has_market_access)
        {{-- ---------- Market data access ---------- --}}
        {{-- Visible to both donors (paid access) and admins (operator
             bypass). The "donor" CSS class keeps the same visual
             treatment — the admin route is a functional bypass, not a
             different UX. --}}
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

        {{-- ---------- Corp / alliance standings ---------- --}}
        {{--
            Standings are OWNED by the corp/alliance (not by an individual
            donor). Any donor in the corp can sync; all donors in the same
            corp/alliance see the same list. Only corp / alliance / faction
            contacts are shown — individual-character contacts are deliberately
            hidden per the donor-UX rule (no personal grudges on a shared
            surface). Downstream battle reports consume these rows for
            donor/admin friendly/enemy tagging.
        --}}
        <section class="card">
            <h2>Corp &amp; alliance standings</h2>
            <p class="subtitle" style="margin-bottom: 1rem;">
                Official standings your corporation and alliance hold toward
                other corps, alliances, and factions. Fetched via your
                authorised character's ESI access. Individual-character
                contacts are not shown here by design — only group-level
                standings feed the automatic battle-report tagging.
            </p>
            <p class="subtitle" style="margin-bottom: 1rem;">
                <strong>Heads-up:</strong> corp contacts need an in-game
                <span class="mono">Personnel_Manager</span> or
                <span class="mono">Contact_Manager</span> role on your
                character. If you don't have it, the corp section just
                stays empty and the alliance section fills in normally —
                no action needed on your end. If neither corp nor alliance
                returns anything (solo NPC-corp, no alliance), we fall
                back to your personal contacts so the battle report still
                has something to work with.
            </p>

            @if ($market_token === null)
                <div class="empty">
                    Authorise market data above first — the same token is used
                    for standings sync.
                </div>
            @else
                @if (count($standings_token_missing_scopes) > 0)
                    <div class="flash warn" style="margin-bottom: 1rem;">
                        Your current authorisation is missing the standings
                        scopes ({{ implode(', ', $standings_token_missing_scopes) }}).
                        Re-authorise via the button above to enable standings sync.
                    </div>
                @else
                    <div style="margin-bottom: 1rem;">
                        <button class="btn"
                                type="button"
                                wire:click="syncStandings"
                                wire:loading.attr="disabled"
                                wire:target="syncStandings">
                            <span wire:loading.remove wire:target="syncStandings">Sync standings now</span>
                            <span wire:loading wire:target="syncStandings">Syncing…</span>
                        </button>
                        <span class="subtitle" style="margin-left: 0.75rem;">
                            Also runs automatically once a day.
                        </span>
                    </div>
                @endif

                @forelse ($standings_by_owner as $owner_type => $bucket)
                    <h3 style="margin-top: 1.5rem; text-transform: capitalize;">
                        {{ $owner_type }}
                        <span class="badge muted">#{{ $bucket['owner_id'] }}</span>
                    </h3>
                    @if ($bucket['rows']->isEmpty())
                        <div class="empty">
                            No {{ $owner_type }} standings stored yet. Click
                            "Sync standings now" above to populate.
                        </div>
                    @else
                        <table>
                            <thead>
                            <tr>
                                <th>Contact</th>
                                <th>Type</th>
                                <th>Standing</th>
                                <th>Class</th>
                                <th>Labels</th>
                                <th>Last synced</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($bucket['rows'] as $row)
                                @php
                                    $class = $row->classification();
                                    $badgeClass = match ($class) {
                                        'friendly' => 'ok',
                                        'enemy' => 'bad',
                                        default => 'muted',
                                    };
                                    $labels = $row->getAttribute('hydrated_labels') ?? [];
                                @endphp
                                <tr>
                                    <td>
                                        {{ $row->contact_name ?? '(unresolved)' }}
                                        <span class="badge muted">#{{ $row->contact_id }}</span>
                                    </td>
                                    <td>{{ $row->contact_type }}</td>
                                    <td class="mono">{{ number_format((float) $row->standing, 1) }}</td>
                                    <td><span class="badge {{ $badgeClass }}">{{ $class }}</span></td>
                                    <td>
                                        @forelse ($labels as $label)
                                            <span class="badge muted">
                                                {{ $label['label_name'] ?? '#'.$label['label_id'] }}
                                            </span>
                                        @empty
                                            <span class="subtitle">—</span>
                                        @endforelse
                                    </td>
                                    <td class="mono">{{ $row->synced_at?->diffForHumans() ?? 'never' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                @empty
                    <div class="empty">
                        No corp/alliance affiliation resolved yet for any of
                        your linked characters. Click "Sync standings now" to
                        pull current affiliation and contacts from ESI.
                    </div>
                @endforelse
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

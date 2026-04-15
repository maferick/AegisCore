{{--
    Livewire component view for /account/settings.

    Sections:
      1. Identity (read-only).
      2. Coalition affiliation (Phase-1 classification onboarding) —
         inferred bloc with confirm / change / re-infer actions.
      3. Market data authorisation (donor-gated) — CTA + token status.
      4. Corp / alliance standings (donor-gated) — sync + grouped view.
      5. Watched structures (donor-gated) — search + add + remove.

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
    /** @var array<string, mixed>|null $viewer_bloc_state */
    /** @var \Illuminate\Support\Collection $coalition_blocs */
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

    {{-- ---------- Coalition affiliation ---------- --}}
    {{--
        Phase 1 of the donor-facing classification system. Surfaces the
        ViewerContext row's bloc state — inferred, confirmed, or needing
        manual pick — with three actions: confirm, pick, re-infer.

        Visible to anyone with a linked character; not donor-gated
        because bloc affiliation is the universal lens the classifier
        uses, and donors-to-be should be able to sanity-check it before
        subscribing. Downstream classification surfaces *are* donor-
        gated (elsewhere).
    --}}
    @if ($viewer_bloc_state !== null)
        <section class="card">
            <h2>Coalition affiliation</h2>
            <p class="subtitle" style="margin-bottom: 1rem;">
                Which coalition your character is looking at the universe
                from. Drives the friendly / hostile / neutral tagging
                across the rest of the platform — when we say "friendly
                to you", we mean friendly <em>to this bloc</em>.
                Individual overrides come later; this is the starting
                lens.
            </p>

            @php
                $vbs = $viewer_bloc_state;
                $vbsBloc = $vbs['bloc'];
                $isConfirmed = (bool) $vbs['is_confirmed'];
                $confidence = $vbs['confidence_band'];
            @endphp

            <div class="kv" style="margin-bottom: 1rem;">
                <div class="kv-label">Character</div>
                <div class="mono">
                    {{ $vbs['character']->name }}
                    <span class="badge muted">#{{ $vbs['character']->character_id }}</span>
                </div>

                <div class="kv-label">Current bloc</div>
                <div>
                    @if ($vbsBloc !== null && $isConfirmed)
                        <span class="badge ok">{{ $vbsBloc->display_name }}</span>
                        <span class="subtitle" style="margin-left: 0.5rem;">confirmed</span>
                    @elseif ($vbsBloc !== null && ! $isConfirmed)
                        <span class="badge warn">suggested: {{ $vbsBloc->display_name }}</span>
                        @if ($confidence)
                            <span class="subtitle" style="margin-left: 0.5rem;">
                                confidence: {{ $confidence }}
                            </span>
                        @endif
                    @else
                        <span class="badge muted">not set</span>
                        <span class="subtitle" style="margin-left: 0.5rem;">
                            no coalition labels found for your alliance or corporation — pick one below
                        </span>
                    @endif
                </div>
            </div>

            @if ($vbsBloc !== null && ! $isConfirmed)
                <div style="margin-bottom: 1rem;">
                    <button class="btn"
                            type="button"
                            wire:click="confirmViewerBloc"
                            wire:loading.attr="disabled"
                            wire:target="confirmViewerBloc">
                        Confirm {{ $vbsBloc->display_name }}
                    </button>
                </div>
            @endif

            <div class="kv" style="margin-bottom: 1rem;">
                <div class="kv-label">Change bloc</div>
                <div>
                    @foreach ($coalition_blocs as $b)
                        <button class="btn secondary"
                                type="button"
                                style="margin: 0 0.25rem 0.25rem 0;"
                                wire:click="setViewerBloc({{ $b->id }})"
                                wire:loading.attr="disabled"
                                wire:target="setViewerBloc">
                            {{ $b->display_name }}
                        </button>
                    @endforeach
                </div>

                <div class="kv-label">Re-run inference</div>
                <div>
                    <button class="btn secondary"
                            type="button"
                            wire:click="reinferViewerBloc"
                            wire:loading.attr="disabled"
                            wire:target="reinferViewerBloc">
                        <span wire:loading.remove wire:target="reinferViewerBloc">Re-infer from labels</span>
                        <span wire:loading wire:target="reinferViewerBloc">Re-inferring…</span>
                    </button>
                    <span class="subtitle" style="margin-left: 0.5rem;">
                        Use if coalition labels were updated recently.
                    </span>
                </div>
            </div>
        </section>
    @endif

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
                    @php
                        $rows = $bucket['rows'];
                        // Group rows by their classification so the grid
                        // renders friendly → neutral → enemy in that order,
                        // which is what a fleet commander scans for first.
                        $groupOrder = ['friendly', 'neutral', 'enemy'];
                        $groups = $rows->groupBy(fn ($r) => $r->classification());
                        // Sync timestamps within a single owner are
                        // effectively identical (single fetch) — surface
                        // one per-owner "last synced" line instead of
                        // repeating the same value on every row.
                        $lastSynced = $rows->max('synced_at');
                    @endphp
                    <div class="standings-owner">
                        <div class="standings-owner-head">
                            <h3>{{ $owner_type }}</h3>
                            <span class="badge muted">#{{ $bucket['owner_id'] }}</span>
                            @if ($rows->isNotEmpty())
                                <span class="standings-owner-meta">
                                    {{ $rows->count() }} {{ \Illuminate\Support\Str::plural('contact', $rows->count()) }}
                                    @if ($lastSynced)
                                        · synced {{ $lastSynced->diffForHumans() }}
                                    @endif
                                </span>
                            @endif
                        </div>

                        @if ($rows->isEmpty())
                            <div class="empty">
                                No {{ $owner_type }} standings stored yet. Click
                                "Sync standings now" above to populate.
                            </div>
                        @else
                            @foreach ($groupOrder as $groupName)
                                @php $groupRows = $groups[$groupName] ?? collect(); @endphp
                                @if ($groupRows->isNotEmpty())
                                    @php
                                        $badgeClass = match ($groupName) {
                                            'friendly' => 'ok',
                                            'enemy' => 'bad',
                                            default => 'muted',
                                        };
                                    @endphp
                                    <div class="standings-group">
                                        <div class="standings-group-head">
                                            <span class="badge {{ $badgeClass }}">{{ $groupName }}</span>
                                            <span class="count">{{ $groupRows->count() }}</span>
                                        </div>
                                        <div class="standings-grid">
                                            @foreach ($groupRows as $row)
                                                @php $labels = $row->getAttribute('hydrated_labels') ?? []; @endphp
                                                <div class="standing-cell {{ $groupName }}"
                                                     title="{{ $row->contact_type }} #{{ $row->contact_id }}">
                                                    <div class="standing-cell-head">
                                                        <div class="standing-cell-name">
                                                            {{ $row->contact_name ?? '(unresolved)' }}
                                                        </div>
                                                        <div class="standing-cell-standing">
                                                            {{ number_format((float) $row->standing, 1) }}
                                                        </div>
                                                    </div>
                                                    <div class="standing-cell-meta">
                                                        <span class="type-tag">{{ $row->contact_type }}</span>
                                                        @foreach ($labels as $label)
                                                            <span class="badge muted">{{ $label['label_name'] ?? '#'.$label['label_id'] }}</span>
                                                        @endforeach
                                                        <span class="id-tag">#{{ $row->contact_id }}</span>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        @endif
                    </div>
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

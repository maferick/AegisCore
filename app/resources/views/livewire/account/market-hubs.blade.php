{{--
    Livewire view for /account/market-hubs.

    Surfaces:
      1. Feature-gate notice (non-donor, non-admin).
      2. Public-reference hubs (Jita + any other NPC hubs the platform
         shows). Read-only; no default-pin affordance.
      3. Private hubs the user is entitled to view, with:
           - Collector count + my-collector indicator.
           - Freeze state (disabled_reason = 'no_active_collector').
           - Set / clear default.
         Registration + revoke stay on /account/settings.
--}}
@php
    /** @var \App\Models\User $user */
    /** @var bool $has_feature_access */
    /** @var \Illuminate\Support\Collection<int, \App\Domains\Markets\Models\MarketHub> $public_hubs */
    /** @var \Illuminate\Support\Collection<int, \App\Domains\Markets\Models\MarketHub> $private_hubs */
    /** @var int|null $default_hub_id */
@endphp
<div>
    @if ($status)
        <div class="flash success">{{ $status }}</div>
    @endif
    @if ($error)
        <div class="flash error">{{ $error }}</div>
    @endif

    {{-- ---------- Feature-gate notice ---------- --}}
    @if (! $has_feature_access)
        <section class="card" id="feature-gate">
            <h2>Private market hubs</h2>
            <p style="color: var(--muted);">
                Private market hubs are a donor benefit. The public reference set
                (Jita and other NPC hubs) is always listed below; donor-registered
                structures unlock when your donor window is active.
            </p>
            <p style="margin-top: 0.75rem;">
                <a class="btn secondary" href="{{ route('filament.portal.pages.account-settings') }}">Back to account settings</a>
            </p>
        </section>
    @endif

    {{-- ---------- Public reference hubs ---------- --}}
    <section class="card" id="public">
        <h2>Public reference</h2>
        <p class="subtitle" style="margin-bottom: 1rem;">
            NPC hubs the platform polls as baseline. Visible to everyone; cannot be set
            as a default (they're always shown alongside your private hub in comparisons).
        </p>
        @if ($public_hubs->isEmpty())
            <div class="empty">No public reference hubs configured.</div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Region</th>
                        <th>Location ID</th>
                        <th>Last sync</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($public_hubs as $hub)
                        <tr>
                            <td>{{ $hub->structure_name ?? '(unnamed)' }}</td>
                            <td>{{ $hub->region?->name ?? '—' }}</td>
                            <td class="mono">{{ $hub->location_id }}</td>
                            <td>
                                @if ($hub->last_sync_at)
                                    <span title="{{ $hub->last_sync_at->toIso8601String() }}">
                                        {{ $hub->last_sync_at->diffForHumans() }}
                                    </span>
                                @else
                                    <span class="badge muted">Never</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    {{-- ---------- Private hubs ---------- --}}
    <section class="card donor" id="private">
        <h2>Private hubs</h2>
        <p class="subtitle" style="margin-bottom: 1rem;">
            Donor-registered player structures you're entitled to view.
            {{-- Register / revoke cross-link: ADR-0005 reserves those flows for
                 /account/settings, which has the ESI-backed structure picker. --}}
            To add or remove a structure, use the
            <a href="{{ route('filament.portal.pages.account-settings') }}#structures" style="color: var(--accent);">structure picker on /account/settings</a>.
        </p>
        @if ($private_hubs->isEmpty())
            <div class="empty">
                @if ($has_feature_access)
                    You're not entitled to view any private hubs yet. Register a structure
                    from <a href="{{ route('filament.portal.pages.account-settings') }}#structures" style="color: var(--accent);">/account/settings</a>.
                @else
                    No private hubs visible. Become a donor and have a hub owner grant you access.
                @endif
            </div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Region</th>
                        <th>Collectors</th>
                        <th>Status</th>
                        <th>Last sync</th>
                        <th style="text-align: right;">Default</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($private_hubs as $hub)
                        @php
                            /** @var \App\Domains\Markets\Models\MarketHubCollector|null $mine */
                            $mine = $hub->getAttribute('my_collector');
                            $frozen = (bool) $hub->getAttribute('is_frozen');
                            $activeCount = (int) $hub->getAttribute('active_collector_count');
                            $totalCount = $hub->collectors->count();
                            $isDefault = $default_hub_id !== null && (int) $default_hub_id === (int) $hub->id;
                        @endphp
                        <tr>
                            <td>
                                {{ $hub->structure_name ?? "#{$hub->location_id}" }}
                                @if ($isDefault)
                                    <span class="badge ok" style="margin-left: 0.4rem;">Default</span>
                                @endif
                            </td>
                            <td>{{ $hub->region?->name ?? '—' }}</td>
                            <td class="mono">
                                {{ $activeCount }} / {{ $totalCount }}
                                @if ($mine)
                                    <span class="badge ok" title="You're a collector on this hub" style="margin-left: 0.3rem;">You</span>
                                    @if ($mine->is_primary)
                                        <span class="badge warn" title="Primary collector" style="margin-left: 0.2rem;">Primary</span>
                                    @endif
                                @endif
                            </td>
                            <td>
                                @if ($frozen)
                                    <span class="badge bad" title="disabled_reason = no_active_collector">Frozen</span>
                                @elseif (! $hub->is_active)
                                    <span class="badge bad">Inactive</span>
                                @else
                                    <span class="badge ok">Live</span>
                                @endif
                            </td>
                            <td>
                                @if ($hub->last_sync_at)
                                    <span title="{{ $hub->last_sync_at->toIso8601String() }}">
                                        {{ $hub->last_sync_at->diffForHumans() }}
                                    </span>
                                @else
                                    <span class="badge muted">Never</span>
                                @endif
                            </td>
                            <td style="text-align: right;">
                                @if ($isDefault)
                                    <button
                                        type="button"
                                        class="btn secondary"
                                        wire:click="clearDefault"
                                        wire:loading.attr="disabled"
                                    >Clear</button>
                                @else
                                    <button
                                        type="button"
                                        class="btn secondary"
                                        wire:click="setDefault({{ $hub->id }})"
                                        wire:loading.attr="disabled"
                                    >Set default</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>

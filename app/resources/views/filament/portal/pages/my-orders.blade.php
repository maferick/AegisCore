<x-filament-panels::page>
    @php
        $fmtIsk = function (?float $v): string {
            if ($v === null) return '—';
            if ($v >= 1e12) return number_format($v / 1e12, 2) . ' T';
            if ($v >= 1e9)  return number_format($v / 1e9, 2) . ' B';
            if ($v >= 1e6)  return number_format($v / 1e6, 2) . ' M';
            if ($v >= 1e3)  return number_format($v / 1e3, 1) . ' K';
            return number_format($v, 0);
        };
    @endphp

    <style>
        .mo-wrap { font-family: 'Inter', sans-serif; color: #e5e5e7; }
        .mo-head { display: flex; gap: 1rem; align-items: baseline; flex-wrap: wrap; margin-bottom: 1rem; }
        .mo-head select, .mo-head a.btn { background: #0c0c0e; color: #e5e5e7; border: 1px solid #26262b; border-radius: 3px; padding: 0.25rem 0.6rem; font-family: 'JetBrains Mono', monospace; font-size: 0.78rem; text-decoration:none; }
        .mo-tabs { display: inline-flex; gap: 0; border: 1px solid #26262b; border-radius: 3px; overflow: hidden; font-family: 'JetBrains Mono', monospace; font-size: 0.72rem; }
        .mo-tabs a { padding: 0.25rem 0.8rem; color: #7a7a82; text-decoration: none; }
        .mo-tabs a.active { background: rgba(79,208,208,0.15); color: #4fd0d0; }
        .mo-tabs a + a { border-left: 1px solid #26262b; }
        .mo-totals { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
        .mo-tile { background: rgba(17,17,19,0.6); border: 1px solid #26262b; border-radius: 8px; padding: 0.9rem 1rem; }
        .mo-tile .label { font-size: 0.6rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(229,229,231,0.45); font-family: 'JetBrains Mono', monospace; }
        .mo-tile .value { font-size: 1.1rem; font-weight: 700; color: #e5e5e7; font-family: 'JetBrains Mono', monospace; margin-top: 0.15rem; font-variant-numeric: tabular-nums; }
        .mo-table { width: 100%; border-collapse: collapse; font-family: 'JetBrains Mono', monospace; font-size: 0.78rem; }
        .mo-table th { text-align: left; padding: 0.5rem 0.6rem; border-bottom: 1px solid #26262b; color: #7a7a82; font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600; }
        .mo-table td { padding: 0.45rem 0.6rem; border-bottom: 1px solid #1a1a1e; font-variant-numeric: tabular-nums; vertical-align: middle; }
        .mo-table .num { text-align: right; }
        .mo-icon { width: 20px; height: 20px; border-radius: 3px; vertical-align: middle; margin-right: 6px; }
        .mo-portrait { width: 18px; height: 18px; border-radius: 50%; vertical-align: middle; margin-right: 4px; }
        .mo-buy  { color: #86efac; }
        .mo-sell { color: #fca5a5; }
        .mo-state-open      { color: #4fd0d0; }
        .mo-state-expired   { color: #7a7a82; }
        .mo-state-cancelled { color: #e5a900; }
        .mo-state-closed    { color: #86efac; }
        .mo-empty { color: #7a7a82; font-style: italic; padding: 1.5rem; text-align: center; border: 1px dashed #26262b; border-radius: 8px; }
        .mo-warn  { background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; padding: 0.55rem 0.8rem; border-radius: 4px; margin-bottom: 1rem; font-size: 0.8rem; }
    </style>

    <div class="mo-wrap">
        @if (! empty($no_user) || ! empty($no_characters))
            <div class="mo-empty">
                No linked characters. Log in via EVE SSO first.
            </div>
        @else
            @if (! empty($missing_scope_character_ids) || ! empty($no_token_character_ids))
                @php
                    $charIdByCid = collect($character_meta)->keyBy('character_id');
                    $missNames = collect($missing_scope_character_ids)->map(fn ($cid) => $charIdByCid[$cid]['name'] ?? "#{$cid}")->implode(', ');
                    $noTokNames = collect($no_token_character_ids)->map(fn ($cid) => $charIdByCid[$cid]['name'] ?? "#{$cid}")->implode(', ');
                @endphp
                <div class="mo-warn">
                    @if (! empty($no_token_character_ids))
                        <strong>{{ $noTokNames }}</strong> {{ count($no_token_character_ids) === 1 ? 'has' : 'have' }} no market token yet.
                    @endif
                    @if (! empty($missing_scope_character_ids))
                        <strong>{{ $missNames }}</strong> need{{ count($missing_scope_character_ids) === 1 ? 's' : '' }} to re-authorise — the read_character_orders scope is missing.
                    @endif
                    Visit <a href="/portal/account-settings" style="color:#4fd0d0;">Account settings</a> and click the market re-auth button for each.
                </div>
            @endif

            <form method="GET" class="mo-head">
                <label style="color:#7a7a82;font-size:0.7rem;text-transform:uppercase;letter-spacing:0.08em;">Character</label>
                <select name="character" onchange="this.form.submit()">
                    <option value="0" @selected($character_filter === 0)>All linked ({{ $characters->count() }})</option>
                    @foreach ($characters as $c)
                        @php $isMain = (int) $c->id === (int) ($main_character_id ?? 0); @endphp
                        <option value="{{ $c->character_id }}" @selected($character_filter === (int) $c->character_id)>
                            {{ $c->name }}{{ $isMain ? ' · main' : ' · alt' }}
                        </option>
                    @endforeach
                </select>

                <div class="mo-tabs">
                    @foreach (['open' => 'Open', 'closed' => 'History', 'all' => 'All'] as $key => $label)
                        <a href="?state={{ $key }}{{ $character_filter > 0 ? '&character=' . $character_filter : '' }}"
                           class="{{ $state_mode === $key ? 'active' : '' }}">{{ $label }}</a>
                    @endforeach
                </div>
                <span style="color:#3a3a42;margin-left:auto;font-size:0.7rem;">
                    {{ $orders->count() }} row{{ $orders->count() === 1 ? '' : 's' }} · hourly sync
                </span>
            </form>

            <div class="mo-totals">
                <div class="mo-tile">
                    <div class="label">Open orders</div>
                    <div class="value">{{ (int) ($totals_open->n ?? 0) }}</div>
                </div>
                <div class="mo-tile">
                    <div class="label">Sell-side ISK on grid</div>
                    <div class="value">{{ $fmtIsk((float) ($totals_open->sell_isk ?? 0)) }}</div>
                </div>
                <div class="mo-tile">
                    <div class="label">Buy-side ISK on grid</div>
                    <div class="value">{{ $fmtIsk((float) ($totals_open->buy_isk ?? 0)) }}</div>
                </div>
            </div>

            @if ($orders->isEmpty())
                <div class="mo-empty">
                    No orders under the current filter. Change filter or wait for the next hourly sync.
                </div>
            @else
                <table class="mo-table">
                    <thead>
                        <tr>
                            <th>Character</th>
                            <th>Item</th>
                            <th>Location</th>
                            <th>Side</th>
                            <th class="num">Price</th>
                            <th class="num">Volume</th>
                            <th>State</th>
                            <th>Issued</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $o)
                            @php
                                $meta = $character_meta[(int) $o->character_id] ?? ['name' => "#{$o->character_id}", 'is_main' => false];
                                $loc = $locations[(int) $o->location_id]['name'] ?? ('Structure ' . substr((string) $o->location_id, -8));
                                $stateClass = 'mo-state-' . $o->state;
                            @endphp
                            <tr>
                                <td>
                                    <img src="https://images.evetech.net/characters/{{ $o->character_id }}/portrait?size=32"
                                         class="mo-portrait" referrerpolicy="no-referrer" alt="">
                                    {{ $meta['name'] }}
                                    @if ($meta['is_main'])
                                        <span style="font-size:0.55rem;color:#4fd0d0;margin-left:3px;">main</span>
                                    @else
                                        <span style="font-size:0.55rem;color:#7a7a82;margin-left:3px;">alt</span>
                                    @endif
                                </td>
                                <td>
                                    <img class="mo-icon" src="https://images.evetech.net/types/{{ $o->type_id }}/icon?size=32" referrerpolicy="no-referrer" alt="">
                                    {{ $o->type_name ?? ('type ' . $o->type_id) }}
                                </td>
                                <td>{{ $loc }}</td>
                                <td class="{{ $o->is_buy ? 'mo-buy' : 'mo-sell' }}">{{ $o->is_buy ? 'Buy' : 'Sell' }}</td>
                                <td class="num">{{ $fmtIsk((float) $o->price) }}</td>
                                <td class="num">{{ number_format($o->volume_remain) }} / {{ number_format($o->volume_total) }}</td>
                                <td><span class="{{ $stateClass }}">{{ $o->state }}</span></td>
                                <td style="color:#7a7a82;">{{ \Carbon\Carbon::parse($o->issued)->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($orders->count() >= 1000)
                    <div style="margin-top:0.6rem;color:#7a7a82;font-size:0.72rem;">Showing first 1000 rows. Narrow the filter to see more.</div>
                @endif
            @endif
        @endif
    </div>
</x-filament-panels::page>

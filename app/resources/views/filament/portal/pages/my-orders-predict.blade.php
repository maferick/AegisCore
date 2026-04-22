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
        .pp-wrap { font-family: 'Inter', sans-serif; color: #e5e5e7; }
        .pp-head { display: flex; gap: 1rem; flex-wrap: wrap; align-items: baseline; margin-bottom: 1rem; color: #7a7a82; font-size: 0.8rem; }
        .pp-head strong { color: #e5e5e7; font-size: 0.95rem; }
        .pp-totals { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap: 0.7rem; margin-bottom: 1rem; }
        .pp-tile { background: rgba(17,17,19,0.6); border: 1px solid #26262b; border-radius: 6px; padding: 0.6rem 0.8rem; }
        .pp-tile .label { font-size: 0.58rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(229,229,231,0.45); font-family: 'JetBrains Mono', monospace; }
        .pp-tile .value { font-size: 1rem; font-weight: 700; color: #e5e5e7; font-family: 'JetBrains Mono', monospace; margin-top: 0.15rem; font-variant-numeric: tabular-nums; }
        .pp-section { margin-top: 1.2rem; }
        .pp-section h3 { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.12em; color: #7a7a82; margin-bottom: 0.4rem; }
        .pp-table { width: 100%; border-collapse: collapse; font-family: 'JetBrains Mono', monospace; font-size: 0.76rem; }
        .pp-table th { text-align: left; padding: 0.45rem 0.55rem; border-bottom: 1px solid #26262b; color: #7a7a82; font-size: 0.6rem; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600; }
        .pp-table td { padding: 0.4rem 0.55rem; border-bottom: 1px solid #1a1a1e; font-variant-numeric: tabular-nums; vertical-align: middle; }
        .pp-table .num { text-align: right; }
        .pp-icon { width: 18px; height: 18px; border-radius: 3px; vertical-align: middle; margin-right: 6px; }
        .pp-band-stock_more    { color: #86efac; font-weight: 700; }
        .pp-band-test_more     { color: #e5a900; font-weight: 700; }
        .pp-band-slow_capital  { color: #c792ea; font-weight: 700; }
        .pp-band-reduce        { color: #fca5a5; font-weight: 700; }
        .pp-band-hold          { color: #4fd0d0; }
        .pp-band-low_data      { color: #7a7a82; font-style: italic; }
        .pp-band-try_new       { color: #e5a900; font-weight: 700; }
        .pp-confidence-high    { color: #86efac; }
        .pp-confidence-medium  { color: #e5a900; }
        .pp-confidence-low     { color: #7a7a82; }
        .pp-priority-high      { color: #ef4444; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; font-size: 0.62rem; }
        .pp-priority-medium    { color: #e5a900; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; font-size: 0.62rem; }
        .pp-priority-low       { color: #7a7a82; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; font-size: 0.62rem; }
        .pp-velocity-fast      { color: #86efac; }
        .pp-velocity-medium    { color: #4fd0d0; }
        .pp-velocity-slow      { color: #c792ea; }
        .pp-price-premium      { color: #86efac; }
        .pp-price-underpriced  { color: #e5a900; }
        .pp-price-aligned      { color: #7a7a82; }
        .pp-empty { color: #7a7a82; font-style: italic; padding: 1.5rem; text-align: center; border: 1px dashed #26262b; border-radius: 8px; }
    </style>

    <div class="pp-wrap">
        @if (! empty($no_user))
            <div class="pp-empty">Log in first.</div>
        @elseif (! empty($no_station))
            <div class="pp-empty">No station selected. Open from the Predict button on /portal/my-orders.</div>
        @else
            <div class="pp-head">
                <span><strong>{{ $station['name'] }}</strong></span>
                @if ($region)
                    <span>Region · {{ $region['name'] }}</span>
                @endif
                <span>Window · {{ \App\Domains\UsersCharacters\Services\PersonalOrderPredictor::WINDOW_DAYS }} days</span>
                <span style="margin-left:auto;">
                    <a href="/portal/my-orders" style="color:#4fd0d0;text-decoration:none;">← back to orders</a>
                </span>
            </div>

            <div class="pp-totals">
                <div class="pp-tile">
                    <div class="label">Items you sold here</div>
                    <div class="value">{{ count($user_types) }}</div>
                </div>
                <div class="pp-tile">
                    <div class="label">Stock more</div>
                    <div class="value pp-band-stock_more">{{ $totals['band_counts']['stock_more'] ?? 0 }}</div>
                </div>
                <div class="pp-tile">
                    <div class="label">Test more</div>
                    <div class="value pp-band-test_more">{{ $totals['band_counts']['test_more'] ?? 0 }}</div>
                </div>
                <div class="pp-tile">
                    <div class="label">Slow capital</div>
                    <div class="value pp-band-slow_capital">{{ $totals['band_counts']['slow_capital'] ?? 0 }}</div>
                </div>
                <div class="pp-tile">
                    <div class="label">Reduce / stop</div>
                    <div class="value pp-band-reduce">{{ $totals['band_counts']['reduce'] ?? 0 }}</div>
                </div>
                <div class="pp-tile">
                    <div class="label">Hold steady</div>
                    <div class="value pp-band-hold">{{ $totals['band_counts']['hold'] ?? 0 }}</div>
                </div>
                <div class="pp-tile">
                    <div class="label">Opportunity items</div>
                    <div class="value pp-band-try_new">{{ $totals['opportunity_types'] }}</div>
                </div>
            </div>

            <div class="pp-section">
                <h3>Items you sold here
                    <span style="font-size:0.6rem; font-weight:400; color:#7a7a82; letter-spacing:0; text-transform:none;">
                        ({{ count($user_types) }} types with at least one finalised sell listing · Jita sell-floor + 10-15% upmarket)
                    </span>
                </h3>
                @if (empty($user_types))
                    <div class="pp-empty">No finalised sell listings here yet. Once one closes in the 90d window it will appear.</div>
                @else
                    <table class="pp-table">
                        <thead>
                            <tr>
                                <th>Priority</th>
                                <th>Item</th>
                                <th>Recommendation</th>
                                <th>Confidence</th>
                                <th>Velocity</th>
                                <th class="num">Listings</th>
                                <th class="num">Sell-through</th>
                                <th class="num">ISK/day</th>
                                <th class="num">Jita sell-floor</th>
                                <th class="num">Sell at (+10-15%)</th>
                                <th class="num">Qty</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($user_types as $r)
                                <tr>
                                    <td><span class="pp-priority-{{ $r['priority'] }}">{{ $r['priority'] }}</span></td>
                                    <td>
                                        <img class="pp-icon" src="https://images.evetech.net/types/{{ $r['type_id'] }}/icon?size=32" referrerpolicy="no-referrer" alt="">
                                        {{ $r['type_name'] }}
                                    </td>
                                    <td><span class="pp-band-{{ $r['band'] }}">{{ str_replace('_', ' ', $r['band']) }}</span></td>
                                    <td>
                                        <span class="pp-confidence-{{ $r['confidence'] }}">{{ $r['confidence'] }}</span>
                                        <span style="color:#7a7a82;font-size:0.62rem;">({{ number_format((float) ($r['confidence_score'] ?? 0), 2) }})</span>
                                    </td>
                                    <td>
                                        @if ($r['velocity'])
                                            <span class="pp-velocity-{{ $r['velocity'] }}">{{ $r['velocity'] }}</span>
                                        @else
                                            <span style="color:#7a7a82;">—</span>
                                        @endif
                                    </td>
                                    <td class="num">{{ $r['listings'] }}</td>
                                    <td class="num">
                                        @if ($r['sell_through_rate'] !== null)
                                            {{ number_format((float) $r['sell_through_rate'] * 100, 0) }}%
                                        @else — @endif
                                    </td>
                                    <td class="num">
                                        @if ($r['isk_per_day'] !== null)
                                            {{ $fmtIsk((float) $r['isk_per_day']) }}
                                        @else
                                            <span style="color:#7a7a82;">—</span>
                                        @endif
                                    </td>
                                    <td class="num">
                                        @if ($r['jita_sell'] !== null)
                                            {{ $fmtIsk((float) $r['jita_sell']) }}
                                        @else
                                            <span style="color:#7a7a82;">—</span>
                                        @endif
                                    </td>
                                    <td class="num">
                                        @if ($r['jita_upmarket_low'] !== null)
                                            <span class="pp-band-stock_more">{{ $fmtIsk((float) $r['jita_upmarket_low']) }}-{{ $fmtIsk((float) $r['jita_upmarket_high']) }}</span>
                                            @if (! empty($r['price_signal']) && $r['price_signal'] !== 'aligned')
                                                <div style="font-size:0.6rem;" class="pp-price-{{ $r['price_signal'] }}">{{ str_replace('_', ' ', $r['price_signal']) }}</div>
                                            @endif
                                        @else
                                            <span style="color:#7a7a82;">—</span>
                                        @endif
                                    </td>
                                    <td class="num">
                                        {{ $r['suggested_qty'] !== null ? number_format($r['suggested_qty']) : '—' }}
                                    </td>
                                    <td style="color:#7a7a82;font-size:0.72rem;">{{ $r['reason'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            @if (! empty($raw_listings))
                <div class="pp-section">
                    <h3>Previous listings here
                        <span style="font-size:0.6rem; font-weight:400; color:#7a7a82; letter-spacing:0; text-transform:none;">
                            ({{ count($raw_listings) }} sell listings, newest first · grows beyond CCP's 90d history cap as rows accumulate)
                        </span>
                    </h3>
                    <table class="pp-table">
                        <thead>
                            <tr>
                                <th>Issued</th>
                                <th>Character</th>
                                <th>Item</th>
                                <th class="num">Price</th>
                                <th class="num">Qty (sold / listed)</th>
                                <th>Outcome</th>
                                <th>Last seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($raw_listings as $l)
                                @php
                                    $m = $character_meta[$l['character_id']] ?? ['name' => 'char #' . $l['character_id'], 'is_main' => false];
                                    $outcomeClass = match ($l['outcome']) {
                                        'sold_out' => 'pp-band-stock_more',
                                        'partial'  => 'pp-band-hold',
                                        'unsold'   => 'pp-band-reduce',
                                        'open'     => 'pp-band-try_new',
                                        default    => '',
                                    };
                                @endphp
                                <tr>
                                    <td style="color:#7a7a82;">{{ \Carbon\Carbon::parse($l['issued'])->format('Y-m-d') }}</td>
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $l['character_id'] }}/portrait?size=32"
                                             class="pp-icon" style="border-radius:50%;" referrerpolicy="no-referrer" alt="">
                                        {{ $m['name'] }}
                                        @if ($m['is_main'])
                                            <span style="font-size:0.55rem;color:#4fd0d0;margin-left:3px;">main</span>
                                        @else
                                            <span style="font-size:0.55rem;color:#7a7a82;margin-left:3px;">alt</span>
                                        @endif
                                    </td>
                                    <td>
                                        <img class="pp-icon" src="https://images.evetech.net/types/{{ $l['type_id'] }}/icon?size=32" referrerpolicy="no-referrer" alt="">
                                        {{ $l['type_name'] }}
                                    </td>
                                    <td class="num">{{ $fmtIsk((float) $l['price']) }}</td>
                                    <td class="num">
                                        {{ number_format($l['volume_sold']) }} / {{ number_format($l['volume_total']) }}
                                    </td>
                                    <td><span class="{{ $outcomeClass }}">{{ str_replace('_', ' ', $l['outcome']) }}</span></td>
                                    <td style="color:#7a7a82;">{{ \Carbon\Carbon::parse($l['last_observed_at'])->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if (! empty($opportunity_types))
                <div class="pp-section">
                    <h3>Opportunity items · region top movers you haven't stocked</h3>
                    <table class="pp-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th class="num">Regional daily volume</th>
                                <th class="num">Regional avg price</th>
                                <th class="num">Try-stock qty (5% of flow × 14d)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($opportunity_types as $r)
                                <tr>
                                    <td>
                                        <img class="pp-icon" src="https://images.evetech.net/types/{{ $r['type_id'] }}/icon?size=32" referrerpolicy="no-referrer" alt="">
                                        {{ $r['type_name'] }}
                                    </td>
                                    <td class="num">{{ number_format((float) $r['daily_volume'], 0) }}</td>
                                    <td class="num">{{ $fmtIsk((float) $r['avg_price']) }}</td>
                                    <td class="num pp-band-try_new">{{ number_format($r['suggested_qty']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>

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
        .dm-wrap { font-family: 'Inter', sans-serif; color: #e5e5e7; }
        .dm-head { display: flex; gap: 1.5rem; align-items: baseline; flex-wrap: wrap; margin-bottom: 1rem; }
        .dm-head select, .dm-head input { background: #0c0c0e; color: #e5e5e7; border: 1px solid #26262b; border-radius: 3px; padding: 0.25rem 0.5rem; font-family: 'JetBrains Mono', monospace; font-size: 0.8rem; }
        .dm-totals { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
        .dm-tile { background: rgba(17,17,19,0.6); border: 1px solid #26262b; border-radius: 8px; padding: 0.9rem 1rem; }
        .dm-tile .label { font-size: 0.6rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(229,229,231,0.45); font-family: 'JetBrains Mono', monospace; }
        .dm-tile .value { font-size: 1.1rem; font-weight: 700; color: #e5e5e7; font-family: 'JetBrains Mono', monospace; margin-top: 0.15rem; font-variant-numeric: tabular-nums; }
        .dm-tile .value.deficit { color: #ff3838; }
        .dm-tile .value.ok { color: #4ade80; }
        .dm-table { width: 100%; border-collapse: collapse; font-family: 'JetBrains Mono', monospace; font-size: 0.78rem; }
        .dm-table th { text-align: left; padding: 0.5rem 0.6rem; border-bottom: 1px solid #26262b; color: #7a7a82; font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600; }
        .dm-table td { padding: 0.45rem 0.6rem; border-bottom: 1px solid #1a1a1e; font-variant-numeric: tabular-nums; }
        .dm-table .num { text-align: right; }
        .dm-table tr.hull td { background: rgba(79,208,208,0.04); }
        .dm-table .deficit { color: #ff3838; font-weight: 700; }
        .dm-table .surplus { color: #4ade80; }
        .dm-runway-bad  { color: #ff3838; font-weight: 700; }
        .dm-runway-warn { color: #e5a900; }
        .dm-runway-ok   { color: #4ade80; }
        .dm-icon { width: 20px; height: 20px; border-radius: 3px; vertical-align: middle; margin-right: 6px; }
        .dm-kind-hull { color: #4fd0d0; font-size: 0.58rem; text-transform: uppercase; letter-spacing: 0.08em; }
        .dm-kind-module { color: #7a7a82; font-size: 0.58rem; text-transform: uppercase; letter-spacing: 0.08em; }
        .dm-buyall { background: #0c0c0e; border: 1px solid #26262b; border-radius: 4px; padding: 0.75rem 1rem; font-family: 'JetBrains Mono', monospace; font-size: 0.78rem; white-space: pre; color: #e5e5e7; max-height: 320px; overflow: auto; }
        .dm-empty { color: #7a7a82; font-style: italic; padding: 1.5rem; text-align: center; border: 1px dashed #26262b; border-radius: 8px; }
    </style>

    <div class="dm-wrap">
        @if ($no_hub)
            <div class="dm-empty">
                No market hub visible. Configure a hub via <a href="/portal/account/market-hubs" style="color:#4fd0d0;">Market Hubs</a> first.
            </div>
        @else
            <form method="GET" class="dm-head">
                <input type="hidden" name="view" value="{{ $view_mode ?? 'deficit' }}">
                <label style="color:#7a7a82;font-size:0.7rem;text-transform:uppercase;letter-spacing:0.08em;">Hub</label>
                <select name="hub" onchange="this.form.submit()">
                    <option value="all" @selected($hub_id === 0)>All hubs ({{ $hubs->count() }}) · aggregate</option>
                    @foreach ($hubs as $h)
                        <option value="{{ $h->id }}" @selected($h->id === $hub_id)>
                            {{ $h->structure_name }} @if ($h->is_public_reference) · public @endif
                        </option>
                    @endforeach
                </select>
                <label style="color:#7a7a82;font-size:0.7rem;text-transform:uppercase;letter-spacing:0.08em;">Target days</label>
                <input type="number" name="days" min="3" max="120" value="{{ $target_days }}" style="width:70px;">
                <label style="color:#7a7a82;font-size:0.7rem;text-transform:uppercase;letter-spacing:0.08em;margin-left:0.6rem;">View</label>
                @php $mode = $view_mode ?? 'deficit'; @endphp
                <div style="display:inline-flex;gap:0;border:1px solid #26262b;border-radius:3px;overflow:hidden;font-family:'JetBrains Mono',monospace;font-size:0.72rem;">
                    <button type="button" onclick="location.search='?hub={{ $hub_id === 0 ? 'all' : $hub_id }}&days={{ $target_days }}&view=deficit'"
                            style="padding:0.25rem 0.7rem;cursor:pointer;border:none;
                                   background:{{ $mode === 'deficit' ? 'rgba(239,68,68,0.2)' : 'transparent' }};
                                   color:{{ $mode === 'deficit' ? '#fca5a5' : '#7a7a82' }};">deficit only</button>
                    <button type="button" onclick="location.search='?hub={{ $hub_id === 0 ? 'all' : $hub_id }}&days={{ $target_days }}&view=all'"
                            style="padding:0.25rem 0.7rem;cursor:pointer;border:none;border-left:1px solid #26262b;
                                   background:{{ $mode === 'all' ? 'rgba(79,208,208,0.15)' : 'transparent' }};
                                   color:{{ $mode === 'all' ? '#4fd0d0' : '#7a7a82' }};">all items</button>
                </div>
                <button type="submit" style="background:rgba(79,208,208,0.1);border:1px solid rgba(79,208,208,0.3);color:#4fd0d0;padding:0.25rem 0.7rem;border-radius:3px;font-size:0.72rem;font-family:'JetBrains Mono',monospace;cursor:pointer;">Apply</button>
                <span style="color:#3a3a42;margin-left:auto;font-size:0.7rem;">
                    Burn window: {{ $window_days }}d losses · {{ $doctrine_count }} doctrine{{ $doctrine_count === 1 ? '' : 's' }}
                    @if (($hidden_count ?? 0) > 0) · {{ $hidden_count }} row{{ $hidden_count === 1 ? '' : 's' }} hidden @endif
                </span>
            </form>

            <div class="dm-totals">
                <div class="dm-tile">
                    <div class="label">Stock at hub</div>
                    <div class="value">{{ $fmtIsk($totals['stock_isk']) }} ISK</div>
                </div>
                <div class="dm-tile">
                    <div class="label">Deficit to {{ $target_days }}d coverage</div>
                    <div class="value {{ $totals['deficit_isk'] > 0 ? 'deficit' : 'ok' }}">
                        {{ $fmtIsk($totals['deficit_isk']) }} ISK
                    </div>
                </div>
                <div class="dm-tile">
                    <div class="label">Lines short</div>
                    <div class="value {{ $totals['deficit_lines'] > 0 ? 'deficit' : 'ok' }}">
                        {{ $totals['deficit_lines'] }} / {{ $totals['lines'] }}
                    </div>
                </div>
            </div>

            @if (empty($rows))
                <div class="dm-empty">
                    No primary doctrines detected for your corp / alliance / bloc. Fly more and come back — doctrine learner needs ≥ 10 losses per hull+fit over the last {{ $window_days }} days.
                </div>
            @else
                <table class="dm-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Kind</th>
                            <th class="num">Weekly burn</th>
                            <th class="num">In stock</th>
                            <th class="num">Runway (d)</th>
                            <th class="num">Target ({{ $target_days }}d)</th>
                            <th class="num">Deficit</th>
                            <th class="num">Est. buy</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            @php
                                $runwayClass = 'dm-runway-ok';
                                if ($r['runway_days'] === null) $runwayClass = '';
                                elseif ($r['runway_days'] < $target_days * 0.33) $runwayClass = 'dm-runway-bad';
                                elseif ($r['runway_days'] < $target_days) $runwayClass = 'dm-runway-warn';
                            @endphp
                            <tr class="{{ $r['kind'] === 'hull' ? 'hull' : '' }}">
                                <td>
                                    <img class="dm-icon" src="https://images.evetech.net/types/{{ $r['type_id'] }}/icon?size=32" referrerpolicy="no-referrer" alt="">
                                    {{ $r['name'] }}
                                    @if ($r['slot'])<span style="color:#7a7a82;font-size:0.62rem;margin-left:6px;">· {{ $r['slot'] }}</span>@endif
                                </td>
                                <td><span class="dm-kind-{{ $r['kind'] }}">{{ $r['kind'] }}</span></td>
                                <td class="num">{{ number_format($r['weekly_burn'], 1) }}</td>
                                <td class="num">{{ number_format($r['stock']) }}</td>
                                <td class="num {{ $runwayClass }}">{{ $r['runway_days'] !== null ? number_format($r['runway_days'], 1) : '∞' }}</td>
                                <td class="num">{{ number_format($r['target_qty']) }}</td>
                                <td class="num {{ $r['deficit_qty'] > 0 ? 'deficit' : ($r['surplus_qty'] > 0 ? 'surplus' : '') }}">
                                    @if ($r['deficit_qty'] > 0)
                                        −{{ number_format($r['deficit_qty']) }}
                                    @elseif ($r['surplus_qty'] > 0)
                                        +{{ number_format($r['surplus_qty']) }}
                                    @else
                                        0
                                    @endif
                                </td>
                                <td class="num">{{ $r['buy_isk'] !== null ? $fmtIsk((float) $r['buy_isk']) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                {{-- Buyall shopping list — paste into EVE's multibuy. --}}
                @php
                    $buyall = collect($rows)
                        ->filter(fn ($r) => $r['deficit_qty'] > 0)
                        ->map(fn ($r) => $r['deficit_qty'] . ' ' . $r['name'])
                        ->values()
                        ->implode("\n");
                @endphp
                @if ($buyall !== '')
                    <h3 style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.12em;color:#7a7a82;margin:1.5rem 0 0.5rem;font-family:'JetBrains Mono',monospace;">Buyall — deficit shopping list</h3>
                    <div class="dm-buyall">{{ $buyall }}</div>
                @endif
            @endif
        @endif
    </div>
</x-filament-panels::page>

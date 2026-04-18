<x-filament-panels::page>
    @php
        $fmtIsk = function (?float $v): string {
            if ($v === null) return '—';
            if ($v >= 1e12) return number_format($v / 1e12, 2) . ' T';
            if ($v >= 1e9)  return number_format($v / 1e9, 2) . ' B';
            if ($v >= 1e6)  return number_format($v / 1e6, 2) . ' M';
            if ($v >= 1e3)  return number_format($v / 1e3, 1) . ' K';
            return number_format($v, 2);
        };
    @endphp

    @if (! $hubs_ready)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Two active hubs (Jita + a player structure) need to be configured in <code>market_hubs</code> before this page has data.
            </p>
        </div>
    @else
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
            <h2 class="text-lg font-semibold">{{ $own_hub['name'] }}</h2>
            <div style="font-size:0.78rem; color:#7a7a82; margin-top:0.15rem;">
                vs reference hub {{ $jita['name'] }} · last sync {{ $own_hub['last_sync_at'] ?? '—' }}
            </div>

            {{-- Summary strip --}}
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:0.75rem; margin-top:1rem;">
                <div style="background:rgba(148,163,184,0.08); border:1px solid rgba(148,163,184,0.2); border-radius:6px; padding:0.6rem 0.8rem;">
                    <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">SKUs tracked</div>
                    <div style="font-size:1.2rem; font-weight:600; color:#e5e5e7; margin-top:0.1rem;">{{ number_format($summary['sku_count']) }}</div>
                </div>
                <div style="background:rgba(34,197,94,0.08); border:1px solid rgba(34,197,94,0.2); border-radius:6px; padding:0.6rem 0.8rem;">
                    <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">On both hubs</div>
                    <div style="font-size:1.2rem; font-weight:600; color:#4ade80; margin-top:0.1rem;">{{ number_format($summary['on_both']) }}</div>
                </div>
                <div style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.2); border-radius:6px; padding:0.6rem 0.8rem;">
                    <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Missing on ours</div>
                    <div style="font-size:1.2rem; font-weight:600; color:#ff6b6b; margin-top:0.1rem;">{{ number_format($summary['missing_on_hub']) }}</div>
                </div>
                <div style="background:rgba(251,191,36,0.08); border:1px solid rgba(251,191,36,0.2); border-radius:6px; padding:0.6rem 0.8rem;">
                    <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Only on ours</div>
                    <div style="font-size:1.2rem; font-weight:600; color:#fbbf24; margin-top:0.1rem;">{{ number_format($summary['only_on_hub']) }}</div>
                </div>
            </div>
        </div>

        {{-- Biggest markups --}}
        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
            <h3 style="font-size:0.85rem; font-weight:600; margin-bottom:0.75rem;">Biggest markups — priced above Jita by 20%+ (top 20)</h3>
            @if (empty($markup))
                <p style="font-size:0.8rem; color:#7a7a82; font-style:italic;">No markups above 20%.</p>
            @else
                <table style="width:100%; font-size:0.8rem; border-collapse:collapse;">
                    <thead>
                        <tr style="text-align:left; color:#7a7a82; text-transform:uppercase; font-size:0.65rem; letter-spacing:0.08em;">
                            <th style="padding:0.3rem 0.5rem;">Item</th>
                            <th style="padding:0.3rem 0.5rem; text-align:right;">Our sell</th>
                            <th style="padding:0.3rem 0.5rem; text-align:right;">Jita sell</th>
                            <th style="padding:0.3rem 0.5rem; text-align:right;">Ratio</th>
                            <th style="padding:0.3rem 0.5rem; text-align:right;">Our qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($markup as $r)
                            <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                <td style="padding:0.35rem 0.5rem; color:#e5e5e7;">
                                    <a href="/portal/market/{{ $r['type_id'] }}" style="display:inline-flex; align-items:center; gap:0.4rem; color:#e5e5e7; text-decoration:none;">
                                        <img src="https://images.evetech.net/types/{{ $r['type_id'] }}/icon?size=32"
                                             referrerpolicy="no-referrer" style="width:20px;height:20px;border-radius:3px;" alt="">
                                        {{ $r['type_name'] }}
                                    </a>
                                </td>
                                <td style="padding:0.35rem 0.5rem; text-align:right; color:#fca5a5;">{{ $fmtIsk($r['hub_sell_price']) }}</td>
                                <td style="padding:0.35rem 0.5rem; text-align:right; color:#9ca3af;">{{ $fmtIsk($r['jita_sell_price']) }}</td>
                                <td style="padding:0.35rem 0.5rem; text-align:right; color:#fbbf24; font-weight:600;">×{{ number_format($r['markup_ratio'], 2) }}</td>
                                <td style="padding:0.35rem 0.5rem; text-align:right; color:#7a7a82;">{{ number_format($r['hub_sell_volume'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Cheaper here --}}
        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
            <h3 style="font-size:0.85rem; font-weight:600; margin-bottom:0.75rem;">Cheaper on our hub — priced below Jita by 10%+ (top 20)</h3>
            @if (empty($cheaper))
                <p style="font-size:0.8rem; color:#7a7a82; font-style:italic;">No SKUs sell cheaper here than Jita.</p>
            @else
                <table style="width:100%; font-size:0.8rem; border-collapse:collapse;">
                    <thead>
                        <tr style="text-align:left; color:#7a7a82; text-transform:uppercase; font-size:0.65rem; letter-spacing:0.08em;">
                            <th style="padding:0.3rem 0.5rem;">Item</th>
                            <th style="padding:0.3rem 0.5rem; text-align:right;">Our sell</th>
                            <th style="padding:0.3rem 0.5rem; text-align:right;">Jita sell</th>
                            <th style="padding:0.3rem 0.5rem; text-align:right;">Ratio</th>
                            <th style="padding:0.3rem 0.5rem; text-align:right;">Our qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cheaper as $r)
                            <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                <td style="padding:0.35rem 0.5rem; color:#e5e5e7;">
                                    <a href="/portal/market/{{ $r['type_id'] }}" style="display:inline-flex; align-items:center; gap:0.4rem; color:#e5e5e7; text-decoration:none;">
                                        <img src="https://images.evetech.net/types/{{ $r['type_id'] }}/icon?size=32"
                                             referrerpolicy="no-referrer" style="width:20px;height:20px;border-radius:3px;" alt="">
                                        {{ $r['type_name'] }}
                                    </a>
                                </td>
                                <td style="padding:0.35rem 0.5rem; text-align:right; color:#86efac;">{{ $fmtIsk($r['hub_sell_price']) }}</td>
                                <td style="padding:0.35rem 0.5rem; text-align:right; color:#9ca3af;">{{ $fmtIsk($r['jita_sell_price']) }}</td>
                                <td style="padding:0.35rem 0.5rem; text-align:right; color:#6ee7b7; font-weight:600;">×{{ number_format($r['markup_ratio'], 2) }}</td>
                                <td style="padding:0.35rem 0.5rem; text-align:right; color:#7a7a82;">{{ number_format($r['hub_sell_volume'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Missing --}}
        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
            <h3 style="font-size:0.85rem; font-weight:600; margin-bottom:0.75rem;">Missing from our hub — available on Jita (top 30 by Jita volume)</h3>
            @if (empty($missing))
                <p style="font-size:0.8rem; color:#7a7a82; font-style:italic;">Nothing material missing on our hub.</p>
            @else
                <table style="width:100%; font-size:0.8rem; border-collapse:collapse;">
                    <thead>
                        <tr style="text-align:left; color:#7a7a82; text-transform:uppercase; font-size:0.65rem; letter-spacing:0.08em;">
                            <th style="padding:0.3rem 0.5rem;">Item</th>
                            <th style="padding:0.3rem 0.5rem; text-align:right;">Jita sell</th>
                            <th style="padding:0.3rem 0.5rem; text-align:right;">Jita qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($missing as $r)
                            <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                <td style="padding:0.35rem 0.5rem; color:#e5e5e7;">
                                    <a href="/portal/market/{{ $r['type_id'] }}" style="display:inline-flex; align-items:center; gap:0.4rem; color:#e5e5e7; text-decoration:none;">
                                        <img src="https://images.evetech.net/types/{{ $r['type_id'] }}/icon?size=32"
                                             referrerpolicy="no-referrer" style="width:20px;height:20px;border-radius:3px;" alt="">
                                        {{ $r['type_name'] }}
                                    </a>
                                </td>
                                <td style="padding:0.35rem 0.5rem; text-align:right; color:#9ca3af;">{{ $fmtIsk($r['jita_sell_price']) }}</td>
                                <td style="padding:0.35rem 0.5rem; text-align:right; color:#cbd5e1;">{{ number_format($r['jita_sell_volume'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif
</x-filament-panels::page>

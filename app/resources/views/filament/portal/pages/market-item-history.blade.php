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

    @if ($not_found ?? false)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">Unknown item.</p>
        </div>
    @else
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
            <div style="display:flex; gap:1rem; align-items:center;">
                <img src="https://images.evetech.net/types/{{ $type['id'] }}/icon?size=128"
                     referrerpolicy="no-referrer"
                     style="width:96px; height:96px; border-radius:8px; border:2px solid rgba(79,208,208,0.25);" alt="">
                <div style="flex:1;">
                    <h2 class="text-xl font-semibold">{{ $type['name'] }}</h2>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:0.75rem; margin-top:0.75rem;">
                        @if (! empty($own_hub))
                            <div style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.2); border-radius:6px; padding:0.6rem 0.8rem;">
                                <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Our hub sell</div>
                                <div style="font-size:1.05rem; font-weight:600; color:#fca5a5;">{{ $fmtIsk($own_hub['sell']['price'] ?? null) }}</div>
                                <div style="font-size:0.66rem; color:#7a7a82;">qty: {{ number_format($own_hub['sell']['volume'] ?? 0) }}</div>
                            </div>
                            <div style="background:rgba(34,197,94,0.05); border:1px solid rgba(34,197,94,0.15); border-radius:6px; padding:0.6rem 0.8rem;">
                                <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Our hub buy</div>
                                <div style="font-size:1.05rem; font-weight:600; color:#86efac;">{{ $fmtIsk($own_hub['buy']['price'] ?? null) }}</div>
                                <div style="font-size:0.66rem; color:#7a7a82;">qty: {{ number_format($own_hub['buy']['volume'] ?? 0) }}</div>
                            </div>
                        @endif
                        @if (! empty($jita))
                            <div style="background:rgba(148,163,184,0.08); border:1px solid rgba(148,163,184,0.2); border-radius:6px; padding:0.6rem 0.8rem;">
                                <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Jita sell</div>
                                <div style="font-size:1.05rem; font-weight:600; color:#cbd5e1;">{{ $fmtIsk($jita['sell']['price'] ?? null) }}</div>
                                <div style="font-size:0.66rem; color:#7a7a82;">qty: {{ number_format($jita['sell']['volume'] ?? 0) }}</div>
                            </div>
                            <div style="background:rgba(148,163,184,0.05); border:1px solid rgba(148,163,184,0.15); border-radius:6px; padding:0.6rem 0.8rem;">
                                <div style="font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">Jita buy</div>
                                <div style="font-size:1.05rem; font-weight:600; color:#cbd5e1;">{{ $fmtIsk($jita['buy']['price'] ?? null) }}</div>
                                <div style="font-size:0.66rem; color:#7a7a82;">qty: {{ number_format($jita['buy']['volume'] ?? 0) }}</div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- 90-day price history chart --}}
        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
            <h3 style="font-size:0.85rem; font-weight:600; margin-bottom:0.75rem;">90-day price history · region {{ $history_region }}</h3>
            @php
                $series = $history ?? [];
                $jitaSeries = $jita_history ?? [];
            @endphp
            @if (empty($series) && empty($jitaSeries))
                <p style="font-size:0.8rem; color:#7a7a82; font-style:italic;">No history recorded.</p>
            @else
                @php
                    // Build overlay chart. Both series plotted on same
                    // x axis (union of dates) and y axis (max of all
                    // average prices).
                    $byDate = [];
                    foreach ($series as $r) $byDate[$r['date']]['hub'] = $r;
                    foreach ($jitaSeries as $r) $byDate[$r['date']]['jita'] = $r;
                    ksort($byDate);
                    $labels = array_keys($byDate);
                    $hubAvg = []; $jitaAvg = []; $volume = [];
                    foreach ($byDate as $d => $pair) {
                        $hubAvg[] = $pair['hub']['average'] ?? null;
                        $jitaAvg[] = $pair['jita']['average'] ?? null;
                        $volume[] = ($pair['hub']['volume'] ?? 0) + ($pair['jita']['volume'] ?? 0);
                    }
                    $validPrices = array_filter(array_merge($hubAvg, $jitaAvg), fn ($v) => $v !== null && $v > 0);
                    $yMax = $validPrices ? max($validPrices) * 1.05 : 1;
                    $yMin = $validPrices ? min($validPrices) * 0.95 : 0;
                    $vMax = $volume ? max($volume) : 1;
                    $w = 800; $h = 220; $pad = 40;
                    $plotW = $w - $pad * 2; $plotH = $h - $pad * 2;
                    $n = count($labels);
                    $xAt = fn ($i) => $pad + ($n > 1 ? $i / ($n - 1) * $plotW : 0);
                    $yAt = fn ($price) => $pad + $plotH - (($price - $yMin) / ($yMax - $yMin) * $plotH);
                    $vAt = fn ($v) => $h - $pad - ($v / $vMax * ($plotH * 0.25));
                @endphp
                <svg viewBox="0 0 {{ $w }} {{ $h }}" style="width:100%; height:auto; max-height:300px; font-family: ui-sans-serif;">
                    {{-- Volume bars (bottom 25%) --}}
                    @foreach ($volume as $i => $v)
                        @if ($v > 0)
                            @php $barX = $xAt($i) - ($plotW / max($n,1) * 0.4); $barW = $plotW / max($n,1) * 0.8; @endphp
                            <rect x="{{ $barX }}" y="{{ $vAt($v) }}"
                                  width="{{ max(1, $barW) }}"
                                  height="{{ $h - $pad - $vAt($v) }}"
                                  fill="rgba(148,163,184,0.25)" />
                        @endif
                    @endforeach
                    {{-- Jita line --}}
                    @php
                        $pathJita = '';
                        $started = false;
                        foreach ($jitaAvg as $i => $p) {
                            if ($p === null) continue;
                            $pathJita .= ($started ? ' L ' : 'M ') . $xAt($i) . ' ' . $yAt($p);
                            $started = true;
                        }
                    @endphp
                    @if ($pathJita)
                        <path d="{{ $pathJita }}" fill="none" stroke="#9ca3af" stroke-width="1.5" />
                    @endif
                    {{-- Own hub line --}}
                    @php
                        $pathHub = '';
                        $started = false;
                        foreach ($hubAvg as $i => $p) {
                            if ($p === null) continue;
                            $pathHub .= ($started ? ' L ' : 'M ') . $xAt($i) . ' ' . $yAt($p);
                            $started = true;
                        }
                    @endphp
                    @if ($pathHub)
                        <path d="{{ $pathHub }}" fill="none" stroke="#f87171" stroke-width="1.8" />
                    @endif
                    {{-- Axes --}}
                    <line x1="{{ $pad }}" y1="{{ $pad }}" x2="{{ $pad }}" y2="{{ $h - $pad }}" stroke="#444" stroke-width="1" />
                    <line x1="{{ $pad }}" y1="{{ $h - $pad }}" x2="{{ $w - $pad }}" y2="{{ $h - $pad }}" stroke="#444" stroke-width="1" />
                    {{-- Y labels --}}
                    <text x="5" y="{{ $yAt($yMax) + 4 }}" fill="#7a7a82" font-size="10">{{ $fmtIsk($yMax) }}</text>
                    <text x="5" y="{{ $yAt($yMin) + 4 }}" fill="#7a7a82" font-size="10">{{ $fmtIsk($yMin) }}</text>
                    {{-- X labels: first + last --}}
                    @if ($n > 0)
                        <text x="{{ $pad }}" y="{{ $h - 8 }}" fill="#7a7a82" font-size="10">{{ $labels[0] }}</text>
                        <text x="{{ $w - $pad - 60 }}" y="{{ $h - 8 }}" fill="#7a7a82" font-size="10">{{ end($labels) }}</text>
                    @endif
                </svg>
                <div style="display:flex; gap:1rem; margin-top:0.5rem; font-size:0.75rem;">
                    <span style="display:inline-flex; gap:0.3rem; align-items:center;"><span style="width:18px; height:2px; background:#f87171;"></span> Our hub region</span>
                    <span style="display:inline-flex; gap:0.3rem; align-items:center;"><span style="width:18px; height:2px; background:#9ca3af;"></span> The Forge (Jita)</span>
                    <span style="display:inline-flex; gap:0.3rem; align-items:center;"><span style="width:10px; height:10px; background:rgba(148,163,184,0.5);"></span> combined volume</span>
                </div>
            @endif
        </div>
    @endif

    <a href="/portal/market" style="font-size:0.85rem; color:#4fd0d0; text-decoration:none;">← back to market overview</a>
</x-filament-panels::page>

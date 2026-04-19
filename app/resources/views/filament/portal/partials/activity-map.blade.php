@php
    $regions = $c['regions'] ?? [];
    $activeCount = $c['active_count'] ?? 0;
    $neighborCount = $c['neighbor_count'] ?? 0;
@endphp
@if (empty($regions))
    <div style="font-size:0.75rem; color:#7a7a82; font-style:italic; padding:0.5rem;">
        No activity in the last 30 days.
    </div>
@else
    <div style="font-size:0.62rem; color:#7a7a82; margin-bottom:0.35rem;">
        {{ $activeCount }} active · {{ $neighborCount }} neighbor systems · {{ count($regions) }} region(s)
    </div>
    @php
        // Horizontal strip — regions sit next to each other and the
        // strip scrolls horizontally if the total is wider than the
        // container. Each panel stays ~380px wide so 3-4 fit on a
        // laptop viewport without shrinking individually.
        $colWidth = count($regions) === 1 ? 'minmax(780px, 1fr)' : 'repeat(' . count($regions) . ', minmax(570px, 1fr))';
    @endphp
    <div style="display:grid; grid-template-columns: {{ $colWidth }}; gap:0.6rem; overflow-x:auto;">
        @foreach ($regions as $region)
            @php
                $amActive = $region['active'];
                $amNeighbors = $region['neighbors'];
                $amGates = $region['gates'];
                $amAnsiblex = $region['ansiblex'] ?? [];
                $amAll = array_merge($amActive, $amNeighbors);
                $xs = array_column($amAll, 'x');
                $ys = array_column($amAll, 'y');
                $minX = min($xs); $maxX = max($xs);
                $minY = min($ys); $maxY = max($ys);
                $spanX = max(1.0, $maxX - $minX);
                $spanY = max(1.0, $maxY - $minY);
                $maxN = max(array_map(fn ($r) => (int) $r['n'], $amActive) ?: [1]);
                $mapWidth = 720; $mapHeight = 560; $padding = 24;
                $plotW = $mapWidth - 2 * $padding;
                $plotH = $mapHeight - 2 * $padding;
                $scale = min($plotW / $spanX, $plotH / $spanY);
                $scaledW = $spanX * $scale;
                $scaledH = $spanY * $scale;
                $offX = ($mapWidth - $scaledW) / 2;
                $offY = ($mapHeight - $scaledH) / 2;
                $toPx = function (float $x, float $y) use ($minX, $maxY, $scale, $offX, $offY): array {
                    return [$offX + ($x - $minX) * $scale, $offY + ($maxY - $y) * $scale];
                };
                $secColor = function (?float $s): string {
                    if ($s === null) return '#888';
                    if ($s >= 0.5) return '#4ade80';
                    if ($s >= 0.0) return '#fbbf24';
                    return '#ef4444';
                };
                $posById = [];
                foreach ($amAll as $sys) { $posById[$sys['id']] = [$sys['x'], $sys['y']]; }
            @endphp
            <div class="wc-region-panel" style="background:#0b0e14; border:1px solid rgba(255,255,255,0.06); border-radius:6px; padding:0.4rem 0.5rem;">
                <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:0.25rem;">
                    <strong style="color:#e5e5e7; font-size:0.82rem;">{{ $region['name'] }}</strong>
                    <span style="color:#7a7a82; font-size:0.65rem;">
                        {{ count($amActive) }} systems · {{ number_format(array_sum(array_column($amActive, 'n'))) }} kills
                    </span>
                </div>
                <svg viewBox="0 0 {{ $mapWidth }} {{ $mapHeight }}"
                     xmlns="http://www.w3.org/2000/svg"
                     preserveAspectRatio="xMidYMid meet"
                     style="width:100%; height:auto; display:block; min-height:480px;">
                    <defs>
                        <radialGradient id="bgGrad-{{ $region['id'] }}" cx="50%" cy="50%" r="70%">
                            <stop offset="0%" stop-color="#10151f" />
                            <stop offset="100%" stop-color="#05070b" />
                        </radialGradient>
                    </defs>
                    <rect x="0" y="0" width="{{ $mapWidth }}" height="{{ $mapHeight }}" fill="url(#bgGrad-{{ $region['id'] }})" />

                    @foreach ($amGates as $pair)
                        @php
                            [$a, $b] = $pair;
                            if (! isset($posById[$a]) || ! isset($posById[$b])) continue;
                            [$ax, $ay] = $posById[$a];
                            [$bx, $by] = $posById[$b];
                            [$px1, $py1] = $toPx($ax, $ay);
                            [$px2, $py2] = $toPx($bx, $by);
                        @endphp
                        <line x1="{{ round($px1, 1) }}" y1="{{ round($py1, 1) }}"
                              x2="{{ round($px2, 1) }}" y2="{{ round($py2, 1) }}"
                              stroke="rgba(148,163,184,0.32)" stroke-width="0.7" />
                    @endforeach

                    {{-- Ansiblex bridges (player jump bridges), yellow
                         dashed curves drawn above stargate lines. --}}
                    @foreach ($amAnsiblex as $pair)
                        @php
                            [$a, $b, $label] = $pair;
                            if (! isset($posById[$a]) || ! isset($posById[$b])) continue;
                            [$ax, $ay] = $posById[$a];
                            [$bx, $by] = $posById[$b];
                            [$px1, $py1] = $toPx($ax, $ay);
                            [$px2, $py2] = $toPx($bx, $by);
                        @endphp
                        <line x1="{{ round($px1, 1) }}" y1="{{ round($py1, 1) }}"
                              x2="{{ round($px2, 1) }}" y2="{{ round($py2, 1) }}"
                              stroke="rgba(250,204,21,0.55)" stroke-width="1.2" stroke-dasharray="4 3">
                            <title>ansiblex{{ $label ? ' · ' . $label : '' }}</title>
                        </line>
                    @endforeach

                    @foreach ($amNeighbors as $sys)
                        @php [$cx, $cy] = $toPx($sys['x'], $sys['y']); $col = $secColor($sys['sec'] ?? null); @endphp
                        <circle cx="{{ round($cx, 1) }}" cy="{{ round($cy, 1) }}" r="1.8"
                                fill="{{ $col }}" fill-opacity="0.3" stroke="none">
                            <title>{{ $sys['name'] }} · {{ $sys['hop'] ?? '?' }}-jump neighbor · sec {{ $sys['sec'] !== null ? number_format($sys['sec'], 2) : '—' }}</title>
                        </circle>
                    @endforeach

                    {{-- Neighbor labels — 1..8 jumps out from an active
                         system get named, dimmer the further out. Past
                         hop 8 the names stay on the hover tooltip only
                         so the map doesn't turn into a wall of text. --}}
                    @foreach ($amNeighbors as $sys)
                        @php
                            $hop = (int) ($sys['hop'] ?? 0);
                            if ($hop < 1 || $hop > 8) continue;
                            [$cx, $cy] = $toPx($sys['x'], $sys['y']);
                            $labelColor = match ($hop) {
                                1 => '#cbd5e1',
                                2 => '#94a3b8',
                                3 => '#94a3b8',
                                4 => '#64748b',
                                5 => '#475569',
                                6 => '#334155',
                                7 => '#334155',
                                8 => '#1e293b',
                            };
                            $labelFont = match ($hop) { 1 => 9, 2 => 8, 3 => 8, 4 => 7, 5 => 7, 6 => 6, 7 => 6, 8 => 6 };
                        @endphp
                        <text x="{{ round($cx + 5, 1) }}" y="{{ round($cy + 2.5, 1) }}"
                              font-size="{{ $labelFont }}" fill="{{ $labelColor }}" style="font-family: ui-monospace, monospace;"
                              stroke="#05070b" stroke-width="2" paint-order="stroke">
                            {{ $sys['name'] }}
                        </text>
                    @endforeach

                    @foreach ($amActive as $sys)
                        @php
                            [$cx, $cy] = $toPx($sys['x'], $sys['y']);
                            $r = max(3, min(16, 3 + sqrt($sys['n']) * 1.8));
                            $opacity = 0.45 + ($sys['n'] / max(1, $maxN)) * 0.5;
                            $col = $secColor($sys['sec'] ?? null);
                        @endphp
                        <circle cx="{{ round($cx, 1) }}" cy="{{ round($cy, 1) }}"
                                r="{{ round($r, 1) }}"
                                fill="{{ $col }}" fill-opacity="{{ round($opacity, 2) }}"
                                stroke="{{ $col }}" stroke-opacity="0.5" stroke-width="0.8">
                            <title>{{ $sys['name'] }} · {{ number_format($sys['n']) }} kills · sec {{ $sys['sec'] !== null ? number_format($sys['sec'], 2) : '—' }}</title>
                        </circle>
                    @endforeach

                    @foreach (array_slice($amActive, 0, 6) as $sys)
                        @php [$cx, $cy] = $toPx($sys['x'], $sys['y']); @endphp
                        <text x="{{ round($cx + 9, 1) }}" y="{{ round($cy + 3, 1) }}"
                              font-size="11" fill="#e5e5e7" style="font-family: ui-monospace, monospace;"
                              stroke="#05070b" stroke-width="2.5" paint-order="stroke">
                            {{ $sys['name'] }}
                        </text>
                    @endforeach
                </svg>
            </div>
        @endforeach
    </div>
    <div style="font-size:0.62rem; color:#7a7a82; margin-top:6px; display:flex; gap:0.9rem; flex-wrap:wrap;">
        <span><span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#4ade80; vertical-align:middle;"></span> hi-sec</span>
        <span><span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#fbbf24; vertical-align:middle;"></span> lo-sec</span>
        <span><span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#ef4444; vertical-align:middle;"></span> null-/w-sec</span>
        <span style="margin-left:auto;">solid = stargate · <span style="color:#fde047;">yellow dashed</span> = ansiblex · dot size = kill count · click region to zoom</span>
    </div>
@endif

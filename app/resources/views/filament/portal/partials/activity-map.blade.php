@php
    $amActive = $c['active'] ?? [];
    $amNeighbors = $c['neighbors'] ?? [];
    $amGates = $c['gates'] ?? [];
    $amTitan = $c['titan'] ?? [];
    $amAll = array_merge($amActive, $amNeighbors);
@endphp
@if (empty($amActive))
    <div style="font-size:0.75rem; color:#7a7a82; font-style:italic; padding:0.5rem;">
        No activity in the last 30 days.
    </div>
@else
    @php
        $xs = array_column($amAll, 'x');
        $ys = array_column($amAll, 'y');
        $minX = min($xs); $maxX = max($xs);
        $minY = min($ys); $maxY = max($ys);
        $spanX = max(1.0, $maxX - $minX);
        $spanY = max(1.0, $maxY - $minY);
        $maxN = max(array_map(fn ($r) => (int) $r['n'], $amActive) ?: [1]);
        // Bigger viewBox for proper map feel; SVG width:100% scales to
        // container, so a larger intrinsic ratio gives a taller render
        // at the same container width.
        $mapWidth = 1100; $mapHeight = 620; $padding = 32;

        $plotW = $mapWidth - 2 * $padding;
        $plotH = $mapHeight - 2 * $padding;
        $scale = min($plotW / $spanX, $plotH / $spanY);
        $scaledW = $spanX * $scale;
        $scaledH = $spanY * $scale;
        $offX = ($mapWidth - $scaledW) / 2;
        $offY = ($mapHeight - $scaledH) / 2;
        $toPx = function (float $x, float $y) use ($minX, $maxY, $scale, $offX, $offY): array {
            $px = $offX + ($x - $minX) * $scale;
            $py = $offY + ($maxY - $y) * $scale;
            return [$px, $py];
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
    <div style="font-size:0.62rem; color:#7a7a82; margin-bottom:0.3rem;">
        {{ count($amActive) }} active · {{ count($amNeighbors) }} neighbor systems
    </div>
    <div style="background:#0b0e14; border:1px solid rgba(255,255,255,0.06); border-radius:6px; padding:0.25rem; min-height:500px;">
        <svg viewBox="0 0 {{ $mapWidth }} {{ $mapHeight }}"
             xmlns="http://www.w3.org/2000/svg"
             preserveAspectRatio="xMidYMid meet"
             style="width:100%; height:auto; display:block; min-height:500px;">
            <defs>
                <radialGradient id="bgGrad" cx="50%" cy="50%" r="70%">
                    <stop offset="0%" stop-color="#10151f" />
                    <stop offset="100%" stop-color="#05070b" />
                </radialGradient>
            </defs>
            <rect x="0" y="0" width="{{ $mapWidth }}" height="{{ $mapHeight }}" fill="url(#bgGrad)" />

            @foreach ($amTitan as $pair)
                @php
                    [$a, $b, $ly] = $pair;
                    if (! isset($posById[$a]) || ! isset($posById[$b])) continue;
                    [$ax, $ay] = $posById[$a];
                    [$bx, $by] = $posById[$b];
                    [$px1, $py1] = $toPx($ax, $ay);
                    [$px2, $py2] = $toPx($bx, $by);
                @endphp
                <line x1="{{ round($px1, 1) }}" y1="{{ round($py1, 1) }}"
                      x2="{{ round($px2, 1) }}" y2="{{ round($py2, 1) }}"
                      stroke="rgba(192,132,252,0.22)" stroke-width="0.4" stroke-dasharray="2 3">
                    <title>titan bridge · {{ number_format($ly, 2) }} LY</title>
                </line>
            @endforeach

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

            @foreach ($amNeighbors as $sys)
                @php [$cx, $cy] = $toPx($sys['x'], $sys['y']); $col = $secColor($sys['sec'] ?? null); @endphp
                <circle cx="{{ round($cx, 1) }}" cy="{{ round($cy, 1) }}" r="1.6"
                        fill="{{ $col }}" fill-opacity="0.3" stroke="none">
                    <title>{{ $sys['name'] }} · neighbor · sec {{ $sys['sec'] !== null ? number_format($sys['sec'], 2) : '—' }}</title>
                </circle>
            @endforeach

            @foreach ($amActive as $sys)
                @php
                    [$cx, $cy] = $toPx($sys['x'], $sys['y']);
                    $r = max(2.5, min(14, 2.5 + sqrt($sys['n']) * 1.6));
                    $opacity = 0.45 + ($sys['n'] / max(1, $maxN)) * 0.5;
                    $col = $secColor($sys['sec'] ?? null);
                @endphp
                <circle cx="{{ round($cx, 1) }}" cy="{{ round($cy, 1) }}"
                        r="{{ round($r, 1) }}"
                        fill="{{ $col }}" fill-opacity="{{ round($opacity, 2) }}"
                        stroke="{{ $col }}" stroke-opacity="0.45" stroke-width="0.7">
                    <title>{{ $sys['name'] }} · {{ number_format($sys['n']) }} kills · sec {{ $sys['sec'] !== null ? number_format($sys['sec'], 2) : '—' }}</title>
                </circle>
            @endforeach

            @foreach (array_slice($amActive, 0, 5) as $sys)
                @php [$cx, $cy] = $toPx($sys['x'], $sys['y']); @endphp
                <text x="{{ round($cx + 8, 1) }}" y="{{ round($cy + 3, 1) }}"
                      font-size="10" fill="#e5e5e7" style="font-family: ui-monospace, monospace;"
                      stroke="#05070b" stroke-width="2.5" paint-order="stroke">
                    {{ $sys['name'] }}
                </text>
            @endforeach
        </svg>
    </div>
    <div style="font-size:0.62rem; color:#7a7a82; margin-top:4px; display:flex; gap:0.9rem; flex-wrap:wrap;">
        <span><span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#4ade80; vertical-align:middle;"></span> hi-sec</span>
        <span><span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#fbbf24; vertical-align:middle;"></span> lo-sec</span>
        <span><span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#ef4444; vertical-align:middle;"></span> null-/w-sec</span>
        <span style="margin-left:auto; color:#7a7a82;">
            solid = stargate · <span style="color:#c084fc;">dashed purple</span> = titan bridge range (≤ 6 LY) · tiny dots = neighbors
        </span>
    </div>
@endif

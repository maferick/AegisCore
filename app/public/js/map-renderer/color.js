// Color helpers for the map renderer.
//
// EVE security-status colors mirror in-game UI: red 0.0 → orange 0.4 →
// yellow 0.5 → green 0.7 → blue 1.0. Hard cutoffs (rather than a smooth
// gradient) match what players expect from the in-game map.
//
// Region coloring uses a deterministic hash so the same region always
// renders in the same hue across reloads — useful when comparing two
// instances side by side.

const SEC_STOPS = [
    { sec: 1.0, color: '#2c69b8' },   // Empire HS deep blue
    { sec: 0.9, color: '#3a7fbf' },
    { sec: 0.8, color: '#48b1c8' },
    { sec: 0.7, color: '#5fc18a' },   // green
    { sec: 0.6, color: '#74d24a' },
    { sec: 0.5, color: '#f0d72e' },   // hi/low cusp — yellow
    { sec: 0.4, color: '#dc6f08' },
    { sec: 0.3, color: '#bf3500' },
    { sec: 0.2, color: '#a01a00' },
    { sec: 0.1, color: '#8c0000' },
    { sec: 0.0, color: '#6e0000' },   // null
    { sec: -1.0, color: '#3a0000' },  // -1.0 is a CCP convention for J-space
];

export function securityColor(sec) {
    if (sec === null || sec === undefined || Number.isNaN(sec)) {
        return '#888';
    }
    const rounded = Math.round(sec * 10) / 10;
    for (const stop of SEC_STOPS) {
        if (rounded >= stop.sec) {
            return stop.color;
        }
    }
    return SEC_STOPS[SEC_STOPS.length - 1].color;
}

// Deterministic 32-bit hash → HSL hue. Saturation/lightness fixed for
// EVE HUD legibility against the dark backdrop.
export function regionColor(regionId) {
    let h = regionId | 0;
    h = ((h ^ 61) ^ (h >>> 16)) | 0;
    h = (h + (h << 3)) | 0;
    h = (h ^ (h >>> 4)) | 0;
    h = Math.imul(h, 0x27d4eb2d) | 0;
    h = (h ^ (h >>> 15)) | 0;
    const hue = ((h % 360) + 360) % 360;
    return `hsl(${hue}, 55%, 60%)`;
}

// Edge color = security-blended midpoint, used by the renderer when a
// jump connects two systems and we want the line to communicate "high
// → low" without a separate gradient stop.
export function edgeColor(secA, secB) {
    if (secA === null && secB === null) return 'rgba(120, 130, 140, 0.55)';
    const a = secA ?? 0;
    const b = secB ?? 0;
    return securityColor((a + b) / 2);
}

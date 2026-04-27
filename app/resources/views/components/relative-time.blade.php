@props([
    'ts' => null,
    'fallback' => '—',
])
@php
    /**
     * <x-relative-time :ts="$row->generated_at" />
     *
     * Renders a short human-relative timestamp ("11h ago", "5m ago",
     * "3d ago") with the absolute UTC time as a tooltip. Accepts
     * Carbon, DateTime, ISO string, or null. Falls back to the
     * configurable placeholder when null.
     *
     * EVE-aware: uses ISO time format to match the in-game UTC
     * convention rather than a localised browser format.
     */
    $absolute = null;
    $relative = $fallback;
    if ($ts !== null && $ts !== '') {
        try {
            $dt = $ts instanceof \DateTimeInterface ? \Carbon\Carbon::instance($ts) : \Carbon\Carbon::parse((string) $ts);
            $absolute = $dt->utc()->format('Y-m-d H:i:s') . ' UTC';
            $diff = now()->diffInSeconds($dt, false); // negative = past
            $secs = abs($diff);
            $suffix = $diff <= 0 ? 'ago' : 'from now';
            if ($secs < 60) {
                $relative = $secs . 's ' . $suffix;
            } elseif ($secs < 3600) {
                $relative = floor($secs / 60) . 'm ' . $suffix;
            } elseif ($secs < 86400) {
                $relative = floor($secs / 3600) . 'h ' . $suffix;
            } elseif ($secs < 30 * 86400) {
                $relative = floor($secs / 86400) . 'd ' . $suffix;
            } else {
                // Falls back to absolute when the gap exceeds a month —
                // reading "73d ago" forces the operator to do calendar
                // math anyway.
                $relative = $dt->utc()->format('M j');
            }
        } catch (\Throwable) {
            $relative = (string) $ts;
            $absolute = (string) $ts;
        }
    }
@endphp
@if ($absolute !== null)
    <span title="{{ $absolute }}" style="cursor:help; border-bottom:1px dotted rgba(255,255,255,0.18);">{{ $relative }}</span>
@else
    <span>{{ $relative }}</span>
@endif

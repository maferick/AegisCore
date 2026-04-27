@props([
    'verdict' => null,  // ['severity'=>..., 'headline'=>..., 'details'=>[]]
])
@if ($verdict)
@php
    $colors = [
        'info' => ['bg' => 'rgba(134,239,172,0.10)', 'border' => '#86efac', 'fg' => '#86efac'],
        'warning' => ['bg' => 'rgba(253,230,138,0.10)', 'border' => '#fde68a', 'fg' => '#fde68a'],
        'elevated' => ['bg' => 'rgba(253,186,116,0.12)', 'border' => '#fdba74', 'fg' => '#fdba74'],
        'critical' => ['bg' => 'rgba(251,113,133,0.14)', 'border' => '#fb7185', 'fg' => '#fb7185'],
    ];
    $vc = $colors[$verdict['severity'] ?? 'info'] ?? $colors['info'];
@endphp
<div class="fi-section rounded-xl"
     style="padding:0.7rem 1rem; margin-bottom:0.75rem;
            background:{{ $vc['bg'] }}; border:1px solid {{ $vc['border'] }};">
    <div style="display:flex; gap:0.6rem; align-items:baseline; flex-wrap:wrap;">
        <span style="font-size:0.55rem; padding:2px 8px; border-radius:3px; background:rgba(0,0,0,0.18); color:{{ $vc['fg'] }}; text-transform:uppercase; letter-spacing:0.1em;">
            {{ $verdict['severity'] ?? 'info' }}
        </span>
        <strong style="font-size:0.95rem; color:#e5e7eb;">{{ $verdict['headline'] ?? '' }}</strong>
    </div>
    @if (! empty($verdict['details']))
        <ul style="margin:0.4rem 0 0 1.2rem; padding:0; font-size:0.72rem; color:#cbd5e1; line-height:1.55;">
            @foreach ($verdict['details'] as $d)
                <li>{{ $d }}</li>
            @endforeach
        </ul>
    @endif
</div>
@endif

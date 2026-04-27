@props([
    'rows' => 4,
    'height' => '14px',
    'gap' => '8px',
    'label' => 'Loading…',
    'minHeight' => null,
])
@once
    <style>
        @keyframes aegis-skel-shimmer {
            0%   { background-position: -400px 0; }
            100% { background-position: 400px 0; }
        }
        .aegis-skel-bone {
            background: linear-gradient(
                90deg,
                rgba(255,255,255,0.04) 0%,
                rgba(255,255,255,0.10) 50%,
                rgba(255,255,255,0.04) 100%
            );
            background-size: 800px 100%;
            border-radius: 4px;
            animation: aegis-skel-shimmer 1.6s linear infinite;
        }
        .aegis-skel-label {
            font-size: 0.7rem;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 0.5rem;
        }
    </style>
@endonce
<div {{ $attributes->merge(['class' => 'aegis-skel']) }}
     @if ($minHeight) style="min-height: {{ $minHeight }};" @endif>
    @if ($label)
        <div class="aegis-skel-label">{{ $label }}</div>
    @endif
    <div style="display:flex; flex-direction:column; gap:{{ $gap }};">
        @for ($i = 0; $i < (int) $rows; $i++)
            @php
                // Slight width variance so the placeholder doesn't look mechanical.
                $w = [100, 92, 80, 88, 76, 95, 70, 90][$i % 8];
            @endphp
            <div class="aegis-skel-bone"
                 style="height: {{ $height }}; width: {{ $w }}%;"></div>
        @endfor
    </div>
</div>

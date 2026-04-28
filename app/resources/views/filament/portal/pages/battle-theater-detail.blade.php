<x-filament-panels::page>
    {{-- Auto-refresh banner: live battles (newest killmail < 6h
         OR ended < 30m ago) poll every 60s via Livewire so the
         operator doesn't have to hit reload. Older battles are
         historical and skip the poll. Opt-out via ?autorefresh=off. --}}
    @php $ar = $auto_refresh ?? ['enabled' => false]; @endphp
    @if ($ar['enabled'])
        <div wire:poll.60s class="fi-section rounded-xl"
             style="display:flex; gap:0.5rem; align-items:center; padding:0.45rem 0.75rem; margin-bottom:0.75rem;
                    background:rgba(134,239,172,0.08); border:1px solid rgba(134,239,172,0.25);
                    font-size:0.7rem; color:#cbd5e1;">
            <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#86efac; box-shadow:0 0 8px #86efac; animation:aegis-pulse 1.4s ease-in-out infinite;"></span>
            <strong style="color:#86efac;">Live</strong>
            <span>auto-refresh every {{ $ar['interval_seconds'] }}s ({{ $ar['reason'] }})</span>
            <a href="{{ $ar['opt_out_url'] }}" style="margin-left:auto; color:#7a7a82; font-size:0.65rem;">pause</a>
        </div>
        <style>
            @keyframes aegis-pulse {
                0%   { opacity: 1;   transform: scale(1); }
                50%  { opacity: 0.4; transform: scale(0.85); }
                100% { opacity: 1;   transform: scale(1); }
            }
        </style>
    @elseif (! empty($ar['opt_out_active']))
        <div style="font-size:0.7rem; color:#7a7a82; margin-bottom:0.5rem;">
            Auto-refresh paused. <a href="{{ $ar['opt_in_url'] }}" style="color:#7dd3fc;">resume</a>
        </div>
    @endif

    {{-- Body lives in partials/battle-theater-body.blade.php so the
         public /battles/{id} controller can render the same rollup
         rendered here without carrying the Filament panel chrome. --}}
    @include('partials.battle-theater-body')
</x-filament-panels::page>

<x-filament-panels::page>
    {{-- Body lives in partials/battle-theater-body.blade.php so the
         public /battles/{id} controller can render the same rollup
         rendered here without carrying the Filament panel chrome. --}}
    @include('partials.battle-theater-body')
</x-filament-panels::page>

<x-filament-panels::page>
    {{-- Body lives in partials/war-report-body.blade.php so the
         public /war-report controller (rendered on killsineve.online)
         can render the same dashboard without the Filament panel
         wrapper. Single source of truth for layout + charts. --}}
    @include('partials.war-report-body')
</x-filament-panels::page>

{{--
    /admin/sde-status — full history of daily SDE drift checks.

    Body is just the Filament table builder output; header widget
    (SdeVersionStatusWidget) renders above this automatically via
    the page's getHeaderWidgets() method. The status card + history
    table both use Filament's bundled CSS so they render correctly
    without a Tailwind/Vite build step.
--}}
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>

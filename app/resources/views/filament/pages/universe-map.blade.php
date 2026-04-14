{{--
    /admin/universe-map — interactive renderer demo + ops surface.

    The form on this page is the live reference for the
    <x-map.renderer> component's prop set; if you add a prop to the
    component, expose it here too. Map data comes from /internal/map/*
    via the renderer's own fetch — Livewire is not in the data path,
    only in the form-state path.
--}}
<x-filament-panels::page>
    {{ $this->form }}

    <div class="fi-section mt-6">
        <div class="fi-section-content p-2">
            <x-map.renderer
                wire:key="map-{{ $scope }}-{{ $regionId }}-{{ $constellationId }}-{{ implode(',', $this->getSystemIdsForRender()) }}-{{ $hops }}-{{ $detail }}"
                :scope="$scope"
                :regionId="$regionId"
                :constellationId="$constellationId"
                :systemIds="$this->getSystemIdsForRender()"
                :hops="(int) $hops"
                :detail="$detail"
                :labelMode="$labelMode"
                :colorBy="$colorBy"
                height="640px"
            />
        </div>
    </div>
</x-filament-panels::page>

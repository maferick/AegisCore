{{-- EVE Map Renderer drop-in component.
     Usage:
         <x-map.renderer scope="region" :regionId="10000002" />
         <x-map.renderer scope="subgraph" :systemIds="$route" :hops="2" />
     The component class is App\View\Components\Map\Renderer; see its
     docblock for the full prop list. --}}
<div
    id="{{ $instanceId }}"
    class="aegis-map-root aegis-map-{{ $instanceId }}"
    data-url="{{ $dataUrl }}"
    data-scope="{{ $scope }}"
    data-label-mode="{{ $labelMode }}"
    data-color-by="{{ $colorBy }}"
    data-interactive="{{ $interactive ? 'true' : 'false' }}"
    data-highlights="{{ json_encode($highlights, JSON_THROW_ON_ERROR) }}"
    data-instance-id="{{ $instanceId }}"
    style="height: {{ $height }};"
></div>

@if ($caption)
    <div class="aegis-map-caption" style="margin-top: 6px; color: #7a7a82; font-size: 12px;">
        {{ $caption }}
    </div>
@endif

{{-- Stylesheet + vendored D3 are page-global, only injected once even
     when the component is rendered multiple times on the same page. --}}
@once
    <link rel="stylesheet" href="{{ asset('js/map-renderer/styles.css') }}">
    <script src="{{ asset('vendor/d3/d3.v7.min.js') }}" defer></script>
@endonce

{{-- Per-instance mount script. We import the module dynamically inside
     a small inline script so each instance gets its own mount call. --}}
<script type="module">
    import { mountMapRenderer } from '{{ asset('js/map-renderer/index.js') }}';
    const root = document.getElementById(@json($instanceId));
    if (root && root.dataset.mounted !== 'true') {
        root.dataset.mounted = 'true';
        mountMapRenderer(root);
    }
</script>

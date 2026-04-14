{{-- EVE Map Renderer drop-in component.
     Usage:
         <x-map.renderer scope="region" :regionId="10000002" />
         <x-map.renderer scope="subgraph" :systemIds="$route" :hops="2" />
     The component class is App\View\Components\Map\Renderer; see its
     docblock for the full prop list. --}}
<div
    {{ $attributes->merge([
        'id' => $instanceId,
        'class' => 'aegis-map-root aegis-map-'.$instanceId,
        'data-url' => $dataUrl,
        'data-scope' => $scope,
        'data-label-mode' => $labelMode,
        'data-color-by' => $colorBy,
        'data-interactive' => $interactive ? 'true' : 'false',
        'data-highlights' => json_encode($highlights, JSON_THROW_ON_ERROR),
        'data-instance-id' => $instanceId,
        'style' => 'height: '.$height.';',
    ]) }}
    x-data="{}"
    x-init="(async () => {
        const mod = await import('{{ asset('js/map-renderer/index.js') }}');
        if ($el.dataset.mounted !== 'true') {
            $el.dataset.mounted = 'true';
            mod.mountMapRenderer($el);
        }
    })()"
></div>

@if ($caption)
    <div class="aegis-map-caption" style="margin-top: 6px; color: #7a7a82; font-size: 12px;">
        {{ $caption }}
    </div>
@endif

{{-- Stylesheet + vendored D3 are page-global, only injected once even
     when the component is rendered multiple times on the same page.
     The per-instance mount runs from the Alpine x-init above — Alpine
     re-runs init() on every fresh DOM node, so when Livewire morphs a
     new renderer block in (e.g. the form's scope / region changes and
     the wire:key pattern on <x-map.renderer /> causes morphdom to
     replace the block), the map automatically re-mounts against the
     new data-url. Inline <script type="module"> tags inside replaced
     fragments are not re-executed by morphdom, which is why the
     original pure-ESM mount was sticky across option changes. --}}
@once
    <link rel="stylesheet" href="{{ asset('js/map-renderer/styles.css') }}">
    <script src="{{ asset('vendor/d3/d3.v7.min.js') }}" defer></script>
@endonce


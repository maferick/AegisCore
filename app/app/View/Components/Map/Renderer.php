<?php

declare(strict_types=1);

namespace App\View\Components\Map;

use App\Reference\Map\Enums\MapScope;
use App\Reference\Map\Enums\ProjectionMode;
use App\Reference\Map\Enums\UniverseDetail;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\View\Component;

/**
 * `<x-map.renderer>` — drop-in EVE map widget.
 *
 * Builds a `data-url` against the public `map.data` route and renders
 * the standard root `<div>` the JS module mounts on. Multiple
 * instances on a single page are isolated by the random instance id.
 *
 * Props (all optional except scope/region_id pair):
 *   scope          'universe' | 'region' | 'constellation' | 'subgraph'
 *   regionId       int (required for region scope)
 *   constellationId int (required for constellation scope)
 *   systemIds      int[] (required for subgraph scope)
 *   hops           int 0..4 (subgraph only, default 0)
 *   detail         'aggregated' | 'dense' (universe only, default aggregated)
 *   projection     'auto' | 'top_down_xz' | 'position_2d' (default auto)
 *   height         CSS length (default '480px')
 *   labelMode      'hover' | 'always' | 'hidden' (default 'hover')
 *   colorBy        'security' | 'region' (default 'security')
 *   interactive    bool (default true)
 *   highlights     int[] of node IDs (default [])
 *   includeJumps   bool (default true)
 *   includeStations bool (default true)
 *   caption        optional string under the chart
 */
class Renderer extends Component
{
    public string $instanceId;

    public string $dataUrl;

    /**
     * @param  array<int, int>  $systemIds
     * @param  array<int, int>  $highlights
     */
    public function __construct(
        public string $scope = 'universe',
        public ?int $regionId = null,
        public ?int $constellationId = null,
        public array $systemIds = [],
        public int $hops = 0,
        public string $detail = 'aggregated',
        public string $projection = 'auto',
        public string $height = '480px',
        public string $labelMode = 'hover',
        public string $colorBy = 'security',
        public bool $interactive = true,
        public array $highlights = [],
        public bool $includeJumps = true,
        public bool $includeStations = true,
        public ?string $caption = null,
    ) {
        $this->instanceId = 'map_'.Str::random(8);
        $this->dataUrl = $this->buildDataUrl();
    }

    public function render(): View|Closure|string
    {
        return view('components.map.renderer');
    }

    /**
     * Compose the JSON endpoint URL for the configured scope. Invalid
     * combinations are surfaced as an empty URL — the JS layer will
     * paint an inline error rather than silently render an empty map.
     */
    private function buildDataUrl(): string
    {
        $scope = MapScope::tryFrom($this->scope);
        if ($scope === null) {
            return '';
        }

        $query = [
            'projection' => ProjectionMode::tryFrom($this->projection)?->value ?? ProjectionMode::AUTO->value,
            'include_jumps' => $this->includeJumps ? '1' : '0',
            'include_stations' => $this->includeStations ? '1' : '0',
        ];

        switch ($scope) {
            case MapScope::UNIVERSE:
                $query['detail'] = UniverseDetail::tryFrom($this->detail)?->value ?? UniverseDetail::AGGREGATED->value;
                break;
            case MapScope::REGION:
                if ($this->regionId === null) {
                    return '';
                }
                $query['region_id'] = $this->regionId;
                break;
            case MapScope::CONSTELLATION:
                if ($this->constellationId === null) {
                    return '';
                }
                $query['constellation_id'] = $this->constellationId;
                break;
            case MapScope::SUBGRAPH:
                if ($this->systemIds === []) {
                    return '';
                }
                $query['system_ids'] = array_values(array_map('intval', $this->systemIds));
                $query['hops'] = max(0, min(4, $this->hops));
                break;
        }

        return route('map.data', ['scope' => $scope->value]).'?'.http_build_query($query);
    }
}

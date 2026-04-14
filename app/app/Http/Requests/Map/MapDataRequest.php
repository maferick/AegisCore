<?php

declare(strict_types=1);

namespace App\Http\Requests\Map;

use App\Reference\Map\Enums\MapScope;
use App\Reference\Map\Enums\ProjectionMode;
use App\Reference\Map\Enums\UniverseDetail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates query-string parameters for the public map endpoint.
 *
 * The route uses `{scope}` as the path segment; everything else
 * (region_id, system_ids, hops, projection, detail, include_*) is on
 * the query string. Rules are split per scope so a "subgraph" call
 * doesn't need to ship a region_id, and a "region" call rejects a
 * subgraph hops argument that would otherwise be silently dropped.
 */
class MapDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Map data is SDE-derived (CCP-public). The endpoint is
        // explicitly public per the design ADR; no auth check here.
        return true;
    }

    public function rules(): array
    {
        $scope = $this->routeScope();

        $base = [
            'projection' => ['nullable', Rule::enum(ProjectionMode::class)],
            'include_jumps' => ['nullable', 'boolean'],
            'include_stations' => ['nullable', 'boolean'],
            'label_limit' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ];

        return match ($scope) {
            MapScope::UNIVERSE => $base + [
                'detail' => ['nullable', Rule::enum(UniverseDetail::class)],
                'region_ids' => ['nullable', 'array', 'max:50'],
                'region_ids.*' => ['integer', 'between:10000000,10999999'],
            ],
            MapScope::REGION => $base + [
                'region_id' => ['required', 'integer', 'between:10000000,10999999'],
            ],
            MapScope::CONSTELLATION => $base + [
                'constellation_id' => ['required', 'integer', 'between:20000000,20999999'],
            ],
            MapScope::SUBGRAPH => $base + [
                'system_ids' => ['required', 'array', 'min:1', 'max:200'],
                'system_ids.*' => ['integer', 'between:30000000,32000000'],
                'hops' => ['nullable', 'integer', 'between:0,4'],
            ],
        };
    }

    public function routeScope(): MapScope
    {
        $value = $this->route('scope');
        $scope = is_string($value) ? MapScope::tryFrom($value) : null;
        if ($scope === null) {
            // Surface as a 404 — unknown scope isn't a validation
            // problem, it's a route mismatch.
            abort(404, 'Unknown map scope.');
        }

        return $scope;
    }
}

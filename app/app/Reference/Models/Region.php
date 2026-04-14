<?php

declare(strict_types=1);

namespace App\Reference\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only EVE region (`ref_regions`).
 *
 * Truncate-reloaded by the SDE importer (ADR-0001 §4); rows here must
 * never be mutated by Laravel. Used by the map renderer's Filament
 * demo page for the region picker, and by any Blade view that needs
 * to display a region name from a foreign key.
 *
 * @property int    $id
 * @property string $name
 * @property int|null $faction_id
 * @property float|null $position_x
 * @property float|null $position_y
 * @property float|null $position_z
 */
class Region extends Model
{
    protected $table = 'ref_regions';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'faction_id' => 'integer',
        'nebula_id' => 'integer',
        'wormhole_class_id' => 'integer',
        'position_x' => 'float',
        'position_y' => 'float',
        'position_z' => 'float',
        'data' => 'array',
    ];
}

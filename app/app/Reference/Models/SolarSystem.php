<?php

declare(strict_types=1);

namespace App\Reference\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only EVE solar system (`ref_solar_systems`).
 *
 * `position2d_x` / `position2d_y` are CCP's schematic 2D coordinates
 * for the in-game 2D map. Nullable: not every system / not every SDE
 * snapshot ships them. The map renderer falls back to a top-down XZ
 * projection of the 3D position when they're missing.
 *
 * @property int $id
 * @property string $name
 * @property int $region_id
 * @property int $constellation_id
 * @property float|null $security_status
 * @property string|null $security_class
 * @property bool $hub
 * @property float|null $position_x
 * @property float|null $position_y
 * @property float|null $position_z
 * @property float|null $position2d_x
 * @property float|null $position2d_y
 */
class SolarSystem extends Model
{
    protected $table = 'ref_solar_systems';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'region_id' => 'integer',
        'constellation_id' => 'integer',
        'star_id' => 'integer',
        'security_status' => 'float',
        'hub' => 'boolean',
        'border' => 'boolean',
        'international' => 'boolean',
        'regional' => 'boolean',
        'luminosity' => 'float',
        'radius' => 'float',
        'position_x' => 'float',
        'position_y' => 'float',
        'position_z' => 'float',
        'position2d_x' => 'float',
        'position2d_y' => 'float',
        'data' => 'array',
    ];

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    /** @return BelongsTo<Constellation, $this> */
    public function constellation(): BelongsTo
    {
        return $this->belongsTo(Constellation::class, 'constellation_id');
    }
}

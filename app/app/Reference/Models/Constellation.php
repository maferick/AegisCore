<?php

declare(strict_types=1);

namespace App\Reference\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only EVE constellation (`ref_constellations`).
 *
 * @property int    $id
 * @property string $name
 * @property int    $region_id
 * @property float|null $position_x
 * @property float|null $position_y
 * @property float|null $position_z
 */
class Constellation extends Model
{
    protected $table = 'ref_constellations';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'region_id' => 'integer',
        'faction_id' => 'integer',
        'wormhole_class_id' => 'integer',
        'position_x' => 'float',
        'position_y' => 'float',
        'position_z' => 'float',
        'data' => 'array',
    ];

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id');
    }
}

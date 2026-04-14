<?php

declare(strict_types=1);

namespace App\Reference\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only EVE stargate (`ref_stargates`).
 *
 * Stargates ship as a one-row-per-side pair (gate A in system X
 * pointing at gate B in system Y, and the inverse). The map renderer's
 * Neo4j projection dedupes via LEAST/GREATEST when building :JUMPS_TO
 * edges; rows here keep both sides for relationship-resolution.
 *
 * @property int $id
 * @property int $solar_system_id
 * @property int|null $destination_system_id
 * @property int|null $destination_stargate_id
 */
class Stargate extends Model
{
    protected $table = 'ref_stargates';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'solar_system_id' => 'integer',
        'destination_system_id' => 'integer',
        'destination_stargate_id' => 'integer',
        'type_id' => 'integer',
        'position_x' => 'float',
        'position_y' => 'float',
        'position_z' => 'float',
        'data' => 'array',
    ];
}

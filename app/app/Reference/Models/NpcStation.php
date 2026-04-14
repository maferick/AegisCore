<?php

declare(strict_types=1);

namespace App\Reference\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only NPC station (`ref_npc_stations`).
 *
 * CCP composes the display name from the operation + orbiting body;
 * we don't materialise it here. Resolve via Cypher join in Neo4j or
 * via PHP join with `ref_station_operations` / `ref_npc_corporations`
 * when needed.
 *
 * @property int $id
 * @property int $solar_system_id
 * @property int|null $owner_id
 * @property int|null $operation_id
 * @property int|null $type_id
 */
class NpcStation extends Model
{
    protected $table = 'ref_npc_stations';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'solar_system_id' => 'integer',
        'owner_id' => 'integer',
        'operation_id' => 'integer',
        'type_id' => 'integer',
        'orbit_id' => 'integer',
        'reprocessing_efficiency' => 'float',
        'reprocessing_stations_take' => 'float',
        'reprocessing_hangar_flag' => 'integer',
        'use_operation_name' => 'boolean',
        'position_x' => 'float',
        'position_y' => 'float',
        'position_z' => 'float',
        'data' => 'array',
    ];
}

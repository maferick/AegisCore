<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Cached entity name resolved from ESI's POST /universe/names/.
 *
 * One row per CCP entity ID (character, corporation, alliance, faction).
 * Upserted by {@see \App\Services\Eve\Esi\EsiNameResolver} and consumed
 * by any code that needs a human-readable name for a bare CCP ID.
 *
 * @property int             $entity_id
 * @property string          $name
 * @property string          $category   'character' | 'corporation' | 'alliance' | 'faction' | ...
 * @property CarbonInterface $cached_at
 */
class EsiEntityName extends Model
{
    protected $table = 'esi_entity_names';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'entity_id',
        'name',
        'category',
        'cached_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'cached_at' => 'datetime',
        ];
    }
}

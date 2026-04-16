<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One corporation membership period for a character.
 *
 * @property int                    $id
 * @property int                    $character_id
 * @property int                    $corporation_id
 * @property int                    $record_id
 * @property CarbonInterface        $start_date
 * @property CarbonInterface|null   $end_date
 * @property bool                   $is_deleted
 * @property CarbonInterface        $fetched_at
 * @property CarbonInterface        $created_at
 * @property CarbonInterface        $updated_at
 */
class CharacterCorporationHistory extends Model
{
    protected $table = 'character_corporation_history';

    protected $fillable = [
        'character_id',
        'corporation_id',
        'record_id',
        'start_date',
        'end_date',
        'is_deleted',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'character_id' => 'integer',
            'corporation_id' => 'integer',
            'record_id' => 'integer',
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'is_deleted' => 'boolean',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * Find which corporation a character was in at a given point in time.
     */
    public static function corporationAt(int $characterId, CarbonInterface $at): ?self
    {
        return static::where('character_id', $characterId)
            ->where('start_date', '<=', $at)
            ->where(function ($q) use ($at) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>', $at);
            })
            ->orderByDesc('start_date')
            ->first();
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One alliance-membership period for a corporation.
 *
 * `alliance_id` is nullable — CCP's alliancehistory endpoint omits the
 * field for periods where the corp was not in any alliance.
 *
 * @property int                    $id
 * @property int                    $corporation_id
 * @property int|null               $alliance_id
 * @property int                    $record_id
 * @property CarbonInterface        $start_date
 * @property CarbonInterface|null   $end_date
 * @property bool                   $is_deleted
 * @property CarbonInterface        $fetched_at
 * @property CarbonInterface        $created_at
 * @property CarbonInterface        $updated_at
 */
class CorporationAllianceHistory extends Model
{
    protected $table = 'corporation_alliance_history';

    protected $fillable = [
        'corporation_id',
        'alliance_id',
        'record_id',
        'start_date',
        'end_date',
        'is_deleted',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'corporation_id' => 'integer',
            'alliance_id' => 'integer',
            'record_id' => 'integer',
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'is_deleted' => 'boolean',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * Find which alliance a corporation was in at a given timestamp.
     * Returns null if no history row covers that point (either because
     * we haven't fetched yet or the corp was independent at the time).
     */
    public static function allianceAt(int $corporationId, CarbonInterface $at): ?self
    {
        return static::where('corporation_id', $corporationId)
            ->where('start_date', '<=', $at)
            ->where(function ($q) use ($at) {
                $q->whereNull('end_date')->orWhere('end_date', '>', $at);
            })
            ->orderByDesc('start_date')
            ->first();
    }
}

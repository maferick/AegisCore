<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-pilot rollup inside a single theater. Materialised by the
 * Python clustering worker; Laravel reads only.
 *
 * Metric contract is locked in ADR-0006 § 1 — this model is a thin
 * Eloquent wrapper; all fields except the FKs and timestamps are
 * numeric metrics derived from the pilot's appearance on killmails
 * within the theater.
 *
 * @property int                  $id
 * @property int                  $theater_id
 * @property int                  $character_id
 * @property int|null             $corporation_id
 * @property int|null             $alliance_id
 * @property int                  $kills
 * @property int                  $final_blows
 * @property int                  $damage_done
 * @property int                  $damage_taken
 * @property int                  $deaths
 * @property string               $isk_lost
 * @property CarbonInterface|null $first_seen_at
 * @property CarbonInterface|null $last_seen_at
 */
class BattleTheaterParticipant extends Model
{
    protected $table = 'battle_theater_participants';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'character_id' => 'integer',
            'corporation_id' => 'integer',
            'alliance_id' => 'integer',
            'kills' => 'integer',
            'final_blows' => 'integer',
            'damage_done' => 'integer',
            'damage_taken' => 'integer',
            'deaths' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function theater(): BelongsTo
    {
        return $this->belongsTo(BattleTheater::class, 'theater_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attacker on a killmail. NPC attackers have a null `character_id`
 * but may carry a `faction_id`. Exactly one attacker per killmail has
 * `is_final_blow = true`.
 *
 * @property int                 $id
 * @property int                 $killmail_id
 * @property int|null            $character_id
 * @property int|null            $corporation_id
 * @property int|null            $alliance_id
 * @property int|null            $faction_id
 * @property int|null            $ship_type_id
 * @property int|null            $weapon_type_id
 * @property int                 $damage_done
 * @property bool                $is_final_blow
 * @property string|null         $security_status
 * @property CarbonInterface     $created_at
 * @property CarbonInterface     $updated_at
 */
class KillmailAttacker extends Model
{
    protected $table = 'killmail_attackers';

    protected $fillable = [
        'killmail_id',
        'character_id',
        'corporation_id',
        'alliance_id',
        'faction_id',
        'ship_type_id',
        'weapon_type_id',
        'damage_done',
        'is_final_blow',
        'security_status',
    ];

    protected function casts(): array
    {
        return [
            'killmail_id' => 'integer',
            'character_id' => 'integer',
            'corporation_id' => 'integer',
            'alliance_id' => 'integer',
            'faction_id' => 'integer',
            'ship_type_id' => 'integer',
            'weapon_type_id' => 'integer',
            'damage_done' => 'integer',
            'is_final_blow' => 'boolean',
            'security_status' => 'decimal:1',
        ];
    }

    // -- relationships ----------------------------------------------------

    public function killmail(): BelongsTo
    {
        return $this->belongsTo(Killmail::class, 'killmail_id');
    }

    // -- scopes -----------------------------------------------------------

    public function scopeFinalBlow($query)
    {
        return $query->where('is_final_blow', true);
    }

    public function scopePlayerOnly($query)
    {
        return $query->whereNotNull('character_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Models;

use App\Reference\Models\Constellation;
use App\Reference\Models\Region;
use App\Reference\Models\SolarSystem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Canonical killmail record — one row per CCP killmail ID.
 *
 * The victim is inlined (no separate table) since there is exactly one
 * per killmail. Valuation aggregates are denormalised from killmail_items
 * and updated during enrichment.
 *
 * @property int                    $killmail_id
 * @property string                 $killmail_hash
 * @property int                    $solar_system_id
 * @property int                    $constellation_id
 * @property int                    $region_id
 * @property CarbonInterface        $killed_at
 * @property int|null               $victim_character_id
 * @property int|null               $victim_corporation_id
 * @property int|null               $victim_alliance_id
 * @property int                    $victim_ship_type_id
 * @property string|null            $victim_ship_type_name
 * @property int|null               $victim_ship_group_id
 * @property string|null            $victim_ship_group_name
 * @property int|null               $victim_ship_category_id
 * @property string|null            $victim_ship_category_name
 * @property int                    $victim_damage_taken
 * @property string                 $total_value
 * @property string                 $hull_value
 * @property string                 $fitted_value
 * @property string                 $cargo_value
 * @property string                 $drone_value
 * @property int                    $attacker_count
 * @property bool                   $is_npc_kill
 * @property bool                   $is_solo_kill
 * @property int|null               $war_id
 * @property int                    $enrichment_version
 * @property CarbonInterface|null   $enriched_at
 * @property CarbonInterface        $ingested_at
 * @property CarbonInterface        $created_at
 * @property CarbonInterface        $updated_at
 */
class Killmail extends Model
{
    protected $table = 'killmails';

    protected $primaryKey = 'killmail_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'killmail_id',
        'killmail_hash',
        'solar_system_id',
        'constellation_id',
        'region_id',
        'killed_at',
        'victim_character_id',
        'victim_corporation_id',
        'victim_alliance_id',
        'victim_ship_type_id',
        'victim_ship_type_name',
        'victim_ship_group_id',
        'victim_ship_group_name',
        'victim_ship_category_id',
        'victim_ship_category_name',
        'victim_damage_taken',
        'total_value',
        'hull_value',
        'fitted_value',
        'cargo_value',
        'drone_value',
        'attacker_count',
        'is_npc_kill',
        'is_solo_kill',
        'war_id',
        'enrichment_version',
        'enriched_at',
        'ingested_at',
    ];

    protected function casts(): array
    {
        return [
            'killmail_id' => 'integer',
            'solar_system_id' => 'integer',
            'constellation_id' => 'integer',
            'region_id' => 'integer',
            'killed_at' => 'datetime',
            'victim_character_id' => 'integer',
            'victim_corporation_id' => 'integer',
            'victim_alliance_id' => 'integer',
            'victim_ship_type_id' => 'integer',
            'victim_ship_group_id' => 'integer',
            'victim_ship_category_id' => 'integer',
            'victim_damage_taken' => 'integer',
            'total_value' => 'decimal:2',
            'hull_value' => 'decimal:2',
            'fitted_value' => 'decimal:2',
            'cargo_value' => 'decimal:2',
            'drone_value' => 'decimal:2',
            'attacker_count' => 'integer',
            'is_npc_kill' => 'boolean',
            'is_solo_kill' => 'boolean',
            'war_id' => 'integer',
            'enrichment_version' => 'integer',
            'enriched_at' => 'datetime',
            'ingested_at' => 'datetime',
        ];
    }

    // -- relationships ----------------------------------------------------

    public function attackers(): HasMany
    {
        return $this->hasMany(KillmailAttacker::class, 'killmail_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(KillmailItem::class, 'killmail_id');
    }

    public function solarSystem(): BelongsTo
    {
        return $this->belongsTo(SolarSystem::class, 'solar_system_id');
    }

    public function constellation(): BelongsTo
    {
        return $this->belongsTo(Constellation::class, 'constellation_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    // -- scopes -----------------------------------------------------------

    public function scopeUnenriched($query)
    {
        return $query->whereNull('enriched_at');
    }

    public function scopeEnriched($query)
    {
        return $query->whereNotNull('enriched_at');
    }

    public function scopeInRegion($query, int $regionId)
    {
        return $query->where('region_id', $regionId);
    }

    public function scopeKilledBetween($query, $from, $to)
    {
        return $query->whereBetween('killed_at', [$from, $to]);
    }

    // -- predicates -------------------------------------------------------

    public function isEnriched(): bool
    {
        return $this->enriched_at !== null;
    }

    public function isNpcKill(): bool
    {
        return (bool) $this->is_npc_kill;
    }

    public function isSoloKill(): bool
    {
        return (bool) $this->is_solo_kill;
    }
}

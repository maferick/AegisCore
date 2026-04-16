<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item line on a killmail — destroyed or dropped. The `flag` is
 * CCP's inventory flag indicating slot position; `slot_category` is
 * the normalised grouping derived from the flag at write time.
 *
 * Valuation columns are nullable: items are written during ingestion,
 * valued during the enrichment pass.
 *
 * @property int                    $id
 * @property int                    $killmail_id
 * @property int                    $type_id
 * @property string|null            $type_name
 * @property int|null               $group_id
 * @property string|null            $group_name
 * @property int|null               $category_id
 * @property string|null            $category_name
 * @property int|null               $meta_group_id
 * @property int|null               $meta_level
 * @property int                    $flag
 * @property int                    $quantity_destroyed
 * @property int                    $quantity_dropped
 * @property int                    $singleton
 * @property string                 $slot_category
 * @property string|null            $unit_value
 * @property string|null            $total_value
 * @property CarbonInterface|null   $valuation_date
 * @property string|null            $valuation_source
 * @property CarbonInterface        $created_at
 * @property CarbonInterface        $updated_at
 */
class KillmailItem extends Model
{
    // -- slot category constants ------------------------------------------

    public const SLOT_HIGH = 'high';

    public const SLOT_MID = 'mid';

    public const SLOT_LOW = 'low';

    public const SLOT_RIG = 'rig';

    public const SLOT_SUBSYSTEM = 'subsystem';

    public const SLOT_SERVICE = 'service';

    public const SLOT_CARGO = 'cargo';

    public const SLOT_DRONE_BAY = 'drone_bay';

    public const SLOT_FIGHTER_BAY = 'fighter_bay';

    public const SLOT_IMPLANT = 'implant';

    public const SLOT_OTHER = 'other';

    // -- valuation source constants ---------------------------------------

    public const VALUATION_JITA_AVERAGE = 'jita_average';

    public const VALUATION_BASE_PRICE = 'base_price';

    public const VALUATION_UNAVAILABLE = 'unavailable';

    protected $table = 'killmail_items';

    protected $fillable = [
        'killmail_id',
        'type_id',
        'type_name',
        'group_id',
        'group_name',
        'category_id',
        'category_name',
        'meta_group_id',
        'meta_level',
        'flag',
        'quantity_destroyed',
        'quantity_dropped',
        'singleton',
        'slot_category',
        'unit_value',
        'total_value',
        'valuation_date',
        'valuation_source',
    ];

    protected function casts(): array
    {
        return [
            'killmail_id' => 'integer',
            'type_id' => 'integer',
            'group_id' => 'integer',
            'category_id' => 'integer',
            'meta_group_id' => 'integer',
            'meta_level' => 'integer',
            'flag' => 'integer',
            'quantity_destroyed' => 'integer',
            'quantity_dropped' => 'integer',
            'singleton' => 'integer',
            'unit_value' => 'decimal:2',
            'total_value' => 'decimal:2',
            'valuation_date' => 'date',
        ];
    }

    // -- relationships ----------------------------------------------------

    public function killmail(): BelongsTo
    {
        return $this->belongsTo(Killmail::class, 'killmail_id');
    }

    // -- scopes -----------------------------------------------------------

    public function scopeInSlot($query, string $slotCategory)
    {
        return $query->where('slot_category', $slotCategory);
    }

    public function scopeDestroyed($query)
    {
        return $query->where('quantity_destroyed', '>', 0);
    }

    public function scopeDropped($query)
    {
        return $query->where('quantity_dropped', '>', 0);
    }

    // -- helpers ----------------------------------------------------------

    public function totalQuantity(): int
    {
        return $this->quantity_destroyed + $this->quantity_dropped;
    }

    /**
     * Map a CCP inventory flag to a normalised slot category.
     *
     * Flag ranges are stable across ESI versions. Source:
     * https://docs.esi.evetech.net/docs/asset_location_id
     * and the SDE invFlags table.
     */
    public static function slotCategoryFromFlag(int $flag): string
    {
        return match (true) {
            // High slots: 27–34 (HiSlot0–HiSlot7)
            $flag >= 27 && $flag <= 34 => self::SLOT_HIGH,

            // Mid slots: 19–26 (MedSlot0–MedSlot7)
            $flag >= 19 && $flag <= 26 => self::SLOT_MID,

            // Low slots: 11–18 (LoSlot0–LoSlot7)
            $flag >= 11 && $flag <= 18 => self::SLOT_LOW,

            // Rig slots: 92–94 (RigSlot0–RigSlot2), 95–99 extended
            $flag >= 92 && $flag <= 99 => self::SLOT_RIG,

            // Subsystems: 125–132 (SubSystemSlot0–SubSystemSlot7)
            $flag >= 125 && $flag <= 132 => self::SLOT_SUBSYSTEM,

            // Service slots (structures): 164–171
            $flag >= 164 && $flag <= 171 => self::SLOT_SERVICE,

            // Drone bay: 87
            $flag === 87 => self::SLOT_DRONE_BAY,

            // Fighter bay: 158
            $flag === 158 => self::SLOT_FIGHTER_BAY,

            // Implants: 89 (implant slot in pod killmails)
            $flag === 89 => self::SLOT_IMPLANT,

            // Cargo: 0 (None/unlisted), 5 (Cargo), 62 (CorpDeliveries),
            // 90 (ShipHangar), 155 (FleetHangar), 154 (SpecializedAmmoHold),
            // and other cargo-like holds
            $flag === 0,
            $flag === 5,
            $flag >= 133 && $flag <= 156,
            $flag === 62,
            $flag === 90 => self::SLOT_CARGO,

            default => self::SLOT_OTHER,
        };
    }
}

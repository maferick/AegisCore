<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Derived per-corporation summary of current + previous alliance,
 * change timestamp, and confidence. One row per CCP corporation id —
 * the PK IS the corporation id, not an autoincrement column.
 *
 * Feeds the resolver's inheritance and alliance-history precedence
 * steps. See migration
 * `2026_04_15_000004_create_corporation_affiliation_profiles_table.php`
 * for why this lives as a denormalised helper rather than columns on a
 * (non-existent) player-corporations ref table.
 *
 * @property int                    $corporation_id        CCP corp id (primary key)
 * @property int|null               $current_alliance_id
 * @property int|null               $previous_alliance_id
 * @property CarbonInterface|null   $last_alliance_change_at
 * @property bool                   $recently_changed_affiliation
 * @property string                 $history_confidence_band  'high' | 'medium' | 'low'
 * @property CarbonInterface        $observed_at
 * @property CarbonInterface        $created_at
 * @property CarbonInterface        $updated_at
 */
class CorporationAffiliationProfile extends Model
{
    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    protected $table = 'corporation_affiliation_profiles';

    // corporation_id is the natural PK. No autoincrement.
    protected $primaryKey = 'corporation_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'corporation_id',
        'current_alliance_id',
        'previous_alliance_id',
        'last_alliance_change_at',
        'recently_changed_affiliation',
        'history_confidence_band',
        'observed_at',
    ];

    protected function casts(): array
    {
        return [
            'corporation_id' => 'integer',
            'current_alliance_id' => 'integer',
            'previous_alliance_id' => 'integer',
            'last_alliance_change_at' => 'datetime',
            'recently_changed_affiliation' => 'boolean',
            'observed_at' => 'datetime',
        ];
    }
}

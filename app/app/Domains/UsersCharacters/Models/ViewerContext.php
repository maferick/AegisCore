<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One viewer context per paying character. Anchors the classification
 * system's per-viewer tenancy: every resolved alignment, override, and
 * cached classification row is keyed to a viewer_context_id.
 *
 * See migration
 * `2026_04_15_000003_create_viewer_contexts_table.php` for the tenancy
 * rationale (why character-level rather than user/corp/alliance).
 *
 * @property int                    $id
 * @property int                    $character_id          FK to characters.id
 * @property int|null               $viewer_corporation_id CCP corp id, cached at last sync
 * @property int|null               $viewer_alliance_id    CCP alliance id, cached at last sync
 * @property int|null               $bloc_id
 * @property string|null            $bloc_confidence_band  'high' | 'medium' | 'low'
 * @property bool                   $bloc_unresolved
 * @property string                 $subscription_status   'active' | 'expired' | 'trialing' | 'none'
 * @property bool                   $is_active
 * @property CarbonInterface|null   $last_recomputed_at
 * @property CarbonInterface        $created_at
 * @property CarbonInterface        $updated_at
 * @property Character              $character
 * @property CoalitionBloc|null     $bloc
 */
class ViewerContext extends Model
{
    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    public const SUBSCRIPTION_ACTIVE = 'active';

    public const SUBSCRIPTION_EXPIRED = 'expired';

    public const SUBSCRIPTION_TRIALING = 'trialing';

    public const SUBSCRIPTION_NONE = 'none';

    protected $table = 'viewer_contexts';

    protected $fillable = [
        'character_id',
        'viewer_corporation_id',
        'viewer_alliance_id',
        'bloc_id',
        'bloc_confidence_band',
        'bloc_unresolved',
        'subscription_status',
        'is_active',
        'last_recomputed_at',
    ];

    protected function casts(): array
    {
        return [
            'character_id' => 'integer',
            'viewer_corporation_id' => 'integer',
            'viewer_alliance_id' => 'integer',
            'bloc_id' => 'integer',
            'bloc_unresolved' => 'boolean',
            'is_active' => 'boolean',
            'last_recomputed_at' => 'datetime',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'character_id');
    }

    public function bloc(): BelongsTo
    {
        return $this->belongsTo(CoalitionBloc::class, 'bloc_id');
    }

    public function classifications(): HasMany
    {
        return $this->hasMany(ViewerEntityClassification::class, 'viewer_context_id');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(EntityClassificationOverride::class, 'viewer_context_id');
    }
}

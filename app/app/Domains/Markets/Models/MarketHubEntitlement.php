<?php

declare(strict_types=1);

namespace App\Domains\Markets\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Viewer access grant for a private market hub.
 *
 * ADR-0005 § Intersection rule: `can_view = has_feature_access AND
 * has_hub_access`. This row represents the `has_hub_access` half for
 * a given (hub, subject) pair. The `has_feature_access` half is
 * evaluated against the user's live donor / admin status at read
 * time — there is no denormalised "entitlement still valid" flag.
 *
 * Three subject flavours, pre-wired for the phase-2 group-sharing
 * rollout:
 *
 *   - 'user'      — subject_id = users.id
 *   - 'corp'      — subject_id = CCP corporation_id
 *   - 'alliance'  — subject_id = CCP alliance_id
 *
 * v1 ships with 'user' only. The policy service refuses to match
 * 'corp' / 'alliance' rows until character → corp / alliance
 * resolution is wired, logging a warning if it encounters them
 * in the DB.
 *
 * Public-reference hubs (Jita) never have entitlement rows: the
 * policy short-circuits on `market_hubs.is_public_reference = true`.
 *
 * @property int                  $id
 * @property int                  $hub_id
 * @property string               $subject_type
 * @property int                  $subject_id
 * @property int|null             $granted_by_user_id
 * @property CarbonInterface      $granted_at
 * @property CarbonInterface      $created_at
 * @property CarbonInterface      $updated_at
 */
class MarketHubEntitlement extends Model
{
    public const SUBJECT_TYPE_USER = 'user';

    public const SUBJECT_TYPE_CORP = 'corp';

    public const SUBJECT_TYPE_ALLIANCE = 'alliance';

    protected $table = 'market_hub_entitlements';

    protected $fillable = [
        'hub_id',
        'subject_type',
        'subject_id',
        'granted_by_user_id',
        'granted_at',
    ];

    protected function casts(): array
    {
        return [
            'hub_id' => 'integer',
            'subject_id' => 'integer',
            'granted_by_user_id' => 'integer',
            'granted_at' => 'datetime',
        ];
    }

    // -- relations --------------------------------------------------------

    public function hub(): BelongsTo
    {
        return $this->belongsTo(MarketHub::class, 'hub_id');
    }

    /**
     * The user who granted this entitlement. NULL-safe: the granter
     * can be deleted while the grant survives. See migration docblock.
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    // -- scopes -----------------------------------------------------------

    public function scopeForUser($query, int $userId)
    {
        return $query
            ->where('subject_type', self::SUBJECT_TYPE_USER)
            ->where('subject_id', $userId);
    }

    public function scopeForCorp($query, int $corpId)
    {
        return $query
            ->where('subject_type', self::SUBJECT_TYPE_CORP)
            ->where('subject_id', $corpId);
    }

    public function scopeForAlliance($query, int $allianceId)
    {
        return $query
            ->where('subject_type', self::SUBJECT_TYPE_ALLIANCE)
            ->where('subject_id', $allianceId);
    }
}

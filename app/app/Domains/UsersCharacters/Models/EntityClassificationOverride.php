<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Models;

use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual override on the resolver's output for a specific entity,
 * either globally (admin-scope) or for a single viewer.
 *
 * Scope invariant (enforced here because MariaDB CHECK constraints are
 * uneven across the deploy targets — see the migration header):
 *
 *     scope_type = 'global'  <=>  viewer_context_id IS NULL
 *     scope_type = 'viewer'  <=>  viewer_context_id IS NOT NULL
 *
 * Enforced via the saving hook and the
 * {@see self::assertValidScope()} helper, which is also directly
 * unit-testable without a DB round-trip.
 *
 * See migration
 * `2026_04_15_000005_create_entity_classification_overrides_table.php`
 * for the precedence chain this override sits inside.
 *
 * @property int                    $id
 * @property string                 $scope_type              'global' | 'viewer'
 * @property int|null               $viewer_context_id
 * @property string                 $target_entity_type      'corporation' | 'alliance'
 * @property int                    $target_entity_id
 * @property string                 $forced_alignment        'friendly' | 'hostile' | 'neutral' | 'unknown'
 * @property string|null            $forced_side_key
 * @property string|null            $forced_role
 * @property string                 $reason
 * @property CarbonInterface|null   $expires_at
 * @property int|null               $created_by_character_id
 * @property bool                   $is_active
 * @property CarbonInterface        $created_at
 * @property CarbonInterface        $updated_at
 * @property ViewerContext|null     $viewerContext
 * @property Character|null         $createdByCharacter
 */
class EntityClassificationOverride extends Model
{
    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_VIEWER = 'viewer';

    public const ENTITY_CORPORATION = 'corporation';

    public const ENTITY_ALLIANCE = 'alliance';

    public const ALIGNMENT_FRIENDLY = 'friendly';

    public const ALIGNMENT_HOSTILE = 'hostile';

    public const ALIGNMENT_NEUTRAL = 'neutral';

    public const ALIGNMENT_UNKNOWN = 'unknown';

    protected $table = 'entity_classification_overrides';

    protected $fillable = [
        'scope_type',
        'viewer_context_id',
        'target_entity_type',
        'target_entity_id',
        'forced_alignment',
        'forced_side_key',
        'forced_role',
        'reason',
        'expires_at',
        'created_by_character_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'viewer_context_id' => 'integer',
            'target_entity_id' => 'integer',
            'expires_at' => 'datetime',
            'created_by_character_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            self::assertValidScope($model->scope_type, $model->viewer_context_id);
        });
    }

    /**
     * Invariant: global overrides MUST NOT carry a viewer_context_id;
     * viewer overrides MUST. Kept static + pure so the rule can be
     * exercised in unit tests without a DB round-trip.
     *
     * @throws DomainException when scope_type and viewer_context_id disagree.
     */
    public static function assertValidScope(string $scopeType, ?int $viewerContextId): void
    {
        if ($scopeType === self::SCOPE_GLOBAL && $viewerContextId !== null) {
            throw new DomainException(
                "Global override must not have a viewer_context_id (got {$viewerContextId})."
            );
        }

        if ($scopeType === self::SCOPE_VIEWER && $viewerContextId === null) {
            throw new DomainException(
                'Viewer override must have a viewer_context_id.'
            );
        }
    }

    public function viewerContext(): BelongsTo
    {
        return $this->belongsTo(ViewerContext::class, 'viewer_context_id');
    }

    public function createdByCharacter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'created_by_character_id');
    }
}

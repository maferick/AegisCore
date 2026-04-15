<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit log for {@see ViewerEntityClassification} writes.
 * Written by the resolver service whenever alignment or confidence
 * band changes for a given (viewer, target) tuple.
 *
 * See migration
 * `2026_04_15_000007_create_viewer_entity_classification_history_table.php`
 * for why this table has no FK on viewer_context_id (history must
 * survive viewer-context deletion for operator forensics).
 *
 * Intentionally has no `updated_at` — append-only means every row is a
 * permanent record of one resolver decision; Eloquent's timestamps()
 * convention doesn't fit.
 *
 * @property int                $id
 * @property int                $viewer_context_id    not FK'd; survives viewer deletion
 * @property string             $target_entity_type   'corporation' | 'alliance'
 * @property int                $target_entity_id
 * @property string|null        $old_alignment        null for the first row per tuple
 * @property string             $new_alignment
 * @property string|null        $old_confidence_band  null for the first row per tuple
 * @property string             $new_confidence_band
 * @property string             $change_reason
 * @property CarbonInterface    $changed_at
 */
class ViewerEntityClassificationHistory extends Model
{
    public const ENTITY_CORPORATION = 'corporation';

    public const ENTITY_ALLIANCE = 'alliance';

    public const ALIGNMENT_FRIENDLY = 'friendly';

    public const ALIGNMENT_HOSTILE = 'hostile';

    public const ALIGNMENT_NEUTRAL = 'neutral';

    public const ALIGNMENT_UNKNOWN = 'unknown';

    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    protected $table = 'viewer_entity_classification_history';

    // Append-only: the table has no updated_at column, and we write
    // changed_at explicitly from the resolver.
    public $timestamps = false;

    protected $fillable = [
        'viewer_context_id',
        'target_entity_type',
        'target_entity_id',
        'old_alignment',
        'new_alignment',
        'old_confidence_band',
        'new_confidence_band',
        'change_reason',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'viewer_context_id' => 'integer',
            'target_entity_id' => 'integer',
            'changed_at' => 'datetime',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resolver output cache. One row per (viewer_context, target entity)
 * tuple. Every donor-facing classification surface reads from this
 * table — no live resolver calls on the render path.
 *
 * See migration
 * `2026_04_15_000006_create_viewer_entity_classifications_table.php`
 * for the invalidation strategy (event-driven `is_dirty` flagging
 * plus a nightly staleness rebuild).
 *
 * @property int                    $id
 * @property int                    $viewer_context_id
 * @property string                 $target_entity_type  'corporation' | 'alliance'
 * @property int                    $target_entity_id
 * @property string                 $resolved_alignment  'friendly' | 'hostile' | 'neutral' | 'unknown'
 * @property string|null            $resolved_side_key
 * @property string|null            $resolved_role
 * @property string                 $confidence_band     'high' | 'medium' | 'low'
 * @property string                 $reason_summary
 * @property array<mixed>|null      $evidence_snapshot
 * @property bool                   $needs_review
 * @property bool                   $is_dirty
 * @property CarbonInterface        $computed_at
 * @property CarbonInterface        $created_at
 * @property CarbonInterface        $updated_at
 * @property ViewerContext          $viewerContext
 */
class ViewerEntityClassification extends Model
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

    protected $table = 'viewer_entity_classifications';

    protected $fillable = [
        'viewer_context_id',
        'target_entity_type',
        'target_entity_id',
        'resolved_alignment',
        'resolved_side_key',
        'resolved_role',
        'confidence_band',
        'reason_summary',
        'evidence_snapshot',
        'needs_review',
        'is_dirty',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'viewer_context_id' => 'integer',
            'target_entity_id' => 'integer',
            'evidence_snapshot' => 'array',
            'needs_review' => 'boolean',
            'is_dirty' => 'boolean',
            'computed_at' => 'datetime',
        ];
    }

    public function viewerContext(): BelongsTo
    {
        return $this->belongsTo(ViewerContext::class, 'viewer_context_id');
    }
}

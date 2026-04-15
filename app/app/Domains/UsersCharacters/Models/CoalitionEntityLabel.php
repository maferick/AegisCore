<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One normalised coalition label attached to a corporation or alliance.
 * The raw string (`wc.member`) is preserved verbatim; the parsed
 * bloc_id / relationship_type_id are the columns the resolver consumes.
 *
 * See migration
 * `2026_04_15_000002_create_coalition_entity_labels_table.php` for why
 * this is separate from the ESI-sourced `character_standing_labels` and
 * why `source` is part of the uniqueness key.
 *
 * @property int                        $id
 * @property string                     $entity_type       'corporation' | 'alliance'
 * @property int                        $entity_id         CCP corp or alliance id
 * @property string                     $raw_label
 * @property int|null                   $bloc_id
 * @property int|null                   $relationship_type_id
 * @property string                     $source            'manual' | 'import' | 'seed' | ...
 * @property bool                       $is_active
 * @property CarbonInterface            $created_at
 * @property CarbonInterface            $updated_at
 * @property CoalitionBloc|null         $bloc
 * @property CoalitionRelationshipType|null $relationshipType
 */
class CoalitionEntityLabel extends Model
{
    public const ENTITY_CORPORATION = 'corporation';

    public const ENTITY_ALLIANCE = 'alliance';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_SEED = 'seed';

    protected $table = 'coalition_entity_labels';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'raw_label',
        'bloc_id',
        'relationship_type_id',
        'source',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'bloc_id' => 'integer',
            'relationship_type_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function bloc(): BelongsTo
    {
        return $this->belongsTo(CoalitionBloc::class, 'bloc_id');
    }

    public function relationshipType(): BelongsTo
    {
        return $this->belongsTo(CoalitionRelationshipType::class, 'relationship_type_id');
    }
}

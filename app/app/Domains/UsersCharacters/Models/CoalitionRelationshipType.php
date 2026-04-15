<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * How an entity relates to a coalition bloc — `member`, `affiliate`,
 * `allied`, `renter`, `logistics`.
 *
 * Pairs with CoalitionBloc to decompose raw labels like `wc.member`
 * into (bloc, relationship_type) on
 * {@see CoalitionEntityLabel}. See migration
 * `2026_04_15_000001_create_coalition_relationship_types_table.php`.
 *
 * @property int             $id
 * @property string          $relationship_code   'member' | 'affiliate' | 'allied' | 'renter' | 'logistics'
 * @property string          $display_name
 * @property string          $default_role
 * @property bool            $inherits_alignment
 * @property int             $display_order
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class CoalitionRelationshipType extends Model
{
    public const CODE_MEMBER = 'member';

    public const CODE_AFFILIATE = 'affiliate';

    public const CODE_ALLIED = 'allied';

    public const CODE_RENTER = 'renter';

    public const CODE_LOGISTICS = 'logistics';

    protected $table = 'coalition_relationship_types';

    protected $fillable = [
        'relationship_code',
        'display_name',
        'default_role',
        'inherits_alignment',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'inherits_alignment' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function entityLabels(): HasMany
    {
        return $this->hasMany(CoalitionEntityLabel::class, 'relationship_type_id');
    }
}

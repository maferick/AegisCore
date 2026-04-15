<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single coalition bloc — a named grouping of alliances and corps that
 * the classification system treats as one strategic side (WinterCo, B2,
 * PanFam, etc.).
 *
 * See migration
 * `2026_04_15_000000_create_coalition_blocs_table.php` for the storage
 * contract and why this lives as a table rather than a hard-coded enum.
 *
 * @property int                  $id
 * @property string               $bloc_code        'wc' | 'b2' | 'cfc' | 'panfam' | 'independent' | 'unknown'
 * @property string               $display_name
 * @property string               $default_role     'combat' | 'support' | 'logistics' | 'renter'
 * @property bool                 $is_active
 * @property CarbonInterface      $created_at
 * @property CarbonInterface      $updated_at
 */
class CoalitionBloc extends Model
{
    public const CODE_WINTERCO = 'wc';

    public const CODE_B2 = 'b2';

    public const CODE_CFC = 'cfc';

    public const CODE_PANFAM = 'panfam';

    public const CODE_INDEPENDENT = 'independent';

    public const CODE_UNKNOWN = 'unknown';

    public const ROLE_COMBAT = 'combat';

    public const ROLE_SUPPORT = 'support';

    public const ROLE_LOGISTICS = 'logistics';

    public const ROLE_RENTER = 'renter';

    protected $table = 'coalition_blocs';

    protected $fillable = [
        'bloc_code',
        'display_name',
        'default_role',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function entityLabels(): HasMany
    {
        return $this->hasMany(CoalitionEntityLabel::class, 'bloc_id');
    }

    public function viewerContexts(): HasMany
    {
        return $this->hasMany(ViewerContext::class, 'bloc_id');
    }
}

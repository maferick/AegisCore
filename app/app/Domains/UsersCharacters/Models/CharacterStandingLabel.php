<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry from CCP's per-owner contact labels list.
 *
 * See the migration file
 * `2026_04_14_000017_create_character_standing_labels_table.php` for
 * the full contract (why it's separate from the standings table, how
 * the UI joins, what the pruning rule is).
 *
 * @property int            $id
 * @property string         $owner_type      'corporation' | 'alliance' | 'character'
 * @property int            $owner_id
 * @property int            $label_id
 * @property string         $label_name
 * @property CarbonInterface $synced_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class CharacterStandingLabel extends Model
{
    protected $table = 'character_standing_labels';

    protected $fillable = [
        'owner_type',
        'owner_id',
        'label_id',
        'label_name',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'owner_id' => 'integer',
            'label_id' => 'integer',
            'synced_at' => 'datetime',
        ];
    }
}

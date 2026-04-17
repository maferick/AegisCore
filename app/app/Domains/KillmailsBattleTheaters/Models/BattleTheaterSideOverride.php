<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Operator-set override of the auto-resolver's side assignment for a
 * single entity in a single theater. See ADR-0006 § 2 addendum.
 *
 * Precedence on read (highest wins):
 *   character > corporation > alliance > auto-resolver.
 *
 * A ``side`` value of ``'exclude'`` drops every killmail + participant
 * for the entity from the rendered report. That's what operators use
 * to clean up "structure shoot caught in the cluster" or "roaming
 * passer-by who got two random kills noise" scenarios.
 *
 * @property int    $id
 * @property int    $theater_id
 * @property string $entity_type  'alliance' | 'corporation' | 'character'
 * @property int    $entity_id
 * @property string $side         'A' | 'B' | 'C' | 'exclude'
 * @property int|null $actor_user_id
 */
class BattleTheaterSideOverride extends Model
{
    protected $table = 'battle_theater_side_overrides';

    protected $guarded = [];

    public const ENTITY_ALLIANCE = 'alliance';
    public const ENTITY_CORPORATION = 'corporation';
    public const ENTITY_CHARACTER = 'character';

    public const SIDE_A = 'A';
    public const SIDE_B = 'B';
    public const SIDE_C = 'C';
    public const SIDE_EXCLUDE = 'exclude';

    /** @return array<int, string> */
    public static function entityTypes(): array
    {
        return [self::ENTITY_ALLIANCE, self::ENTITY_CORPORATION, self::ENTITY_CHARACTER];
    }

    /** @return array<int, string> */
    public static function sides(): array
    {
        return [self::SIDE_A, self::SIDE_B, self::SIDE_C, self::SIDE_EXCLUDE];
    }
}

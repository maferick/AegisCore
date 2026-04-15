<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single corp/alliance standing row — "owner X regards contact Z at
 * standing S".
 *
 * See the migration file
 * `2026_04_14_000016_create_character_standings_table.php` for the
 * full contract (why owners are group-level only, how the battle-report
 * downstream consumes it, and what the DECIMAL(4,1) range means).
 *
 * This model is intentionally thin — no lifecycle hooks, no scopes.
 * The fetch path upserts via query builder for performance; the
 * read paths on /account/settings want raw collections.
 *
 * @property int                 $id
 * @property string              $owner_type      'corporation' | 'alliance'
 * @property int                 $owner_id        CCP corp_id or alliance_id
 * @property int                 $contact_id      CCP entity ID
 * @property string              $contact_type    'character' | 'corporation' | 'alliance' | 'faction'
 * @property string              $standing        Decimal string, -10.0 to +10.0
 * @property string|null         $contact_name    Display name resolved via /universe/names/
 * @property array<int, int>|null $label_ids
 * @property int|null            $source_character_id
 * @property CarbonInterface     $synced_at
 * @property CarbonInterface     $created_at
 * @property CarbonInterface     $updated_at
 */
class CharacterStanding extends Model
{
    public const OWNER_CORPORATION = 'corporation';

    public const OWNER_ALLIANCE = 'alliance';

    public const CONTACT_CHARACTER = 'character';

    public const CONTACT_CORPORATION = 'corporation';

    public const CONTACT_ALLIANCE = 'alliance';

    public const CONTACT_FACTION = 'faction';

    protected $table = 'character_standings';

    protected $fillable = [
        'owner_type',
        'owner_id',
        'contact_id',
        'contact_type',
        'standing',
        'contact_name',
        'label_ids',
        'source_character_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'owner_id' => 'integer',
            'contact_id' => 'integer',
            'source_character_id' => 'integer',
            'label_ids' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function sourceCharacter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'source_character_id');
    }

    /**
     * Normalised sign for the battle-report friendly/enemy tag. Keeps
     * the threshold in one place so the /account/settings display and
     * the downstream report agree on what "blue" means.
     *
     *   >= +5.0  → 'friendly'
     *   <= -5.0  → 'enemy'
     *   else     → 'neutral'
     *
     * CCP's in-game UI uses ±5 as the "standing applied" threshold for
     * most contact-sensitive game behaviour (fleet autojoin, station
     * service access), so mirroring it avoids a per-surface argument
     * about where to draw the line.
     */
    public function classification(): string
    {
        $value = (float) $this->standing;
        if ($value >= 5.0) {
            return 'friendly';
        }
        if ($value <= -5.0) {
            return 'enemy';
        }

        return 'neutral';
    }
}

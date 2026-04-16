<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Models;

use App\Reference\Models\SolarSystem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-system rollup inside a single theater. Renders the "where did
 * the fighting happen" slice of the detail page without re-joining
 * killmails + ref_solar_systems at render time.
 *
 * @property int                  $id
 * @property int                  $theater_id
 * @property int                  $solar_system_id
 * @property int                  $kill_count
 * @property string               $isk_lost
 * @property CarbonInterface|null $first_kill_at
 * @property CarbonInterface|null $last_kill_at
 */
class BattleTheaterSystem extends Model
{
    protected $table = 'battle_theater_systems';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'solar_system_id' => 'integer',
            'kill_count' => 'integer',
            'first_kill_at' => 'datetime',
            'last_kill_at' => 'datetime',
        ];
    }

    public function theater(): BelongsTo
    {
        return $this->belongsTo(BattleTheater::class, 'theater_id');
    }

    public function solarSystem(): BelongsTo
    {
        return $this->belongsTo(SolarSystem::class, 'solar_system_id');
    }
}

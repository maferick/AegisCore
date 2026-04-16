<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Models;

use App\Reference\Models\Region;
use App\Reference\Models\SolarSystem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per cluster of dense killmail activity. Rollups are
 * materialised by the Python theater_clustering worker per
 * ADR-0006; Laravel reads only.
 *
 * @property int                  $id
 * @property int                  $primary_system_id
 * @property int                  $region_id
 * @property CarbonInterface      $start_time
 * @property CarbonInterface      $end_time
 * @property int                  $total_kills
 * @property string               $total_isk_lost  // decimal cast to string
 * @property int                  $participant_count
 * @property int                  $system_count
 * @property CarbonInterface|null $locked_at
 * @property string|null          $snapshot_json
 */
class BattleTheater extends Model
{
    protected $table = 'battle_theaters';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'total_kills' => 'integer',
            'participant_count' => 'integer',
            'system_count' => 'integer',
            'locked_at' => 'datetime',
        ];
    }

    public function participants(): HasMany
    {
        return $this->hasMany(BattleTheaterParticipant::class, 'theater_id');
    }

    public function systems(): HasMany
    {
        return $this->hasMany(BattleTheaterSystem::class, 'theater_id');
    }

    /**
     * Many-to-many via the pivot table. The pivot has no
     * columns of its own beyond the keys.
     */
    public function killmails(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Domains\KillmailsBattleTheaters\Models\Killmail::class,
            'battle_theater_killmails',
            'theater_id',
            'killmail_id',
            'id',
            'killmail_id',
        );
    }

    public function primarySystem(): BelongsTo
    {
        return $this->belongsTo(SolarSystem::class, 'primary_system_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /** Duration in seconds between start_time and end_time. */
    public function durationSeconds(): int
    {
        return (int) max(0, $this->end_time->diffInSeconds($this->start_time));
    }

    /** Human-readable label. Used by the list page. */
    public function label(): string
    {
        $system = $this->primarySystem?->name ?? "#{$this->primary_system_id}";
        $when = $this->start_time?->toIso8601String() ?? '(unknown)';

        return "{$system} • {$when}";
    }
}

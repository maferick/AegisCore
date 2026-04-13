<?php

declare(strict_types=1);

namespace App\Reference\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per daily SDE version-drift check.
 *
 * Inserts are append-only. The Filament widget reads the latest row via
 * {@see self::scopeLatest()}; the /admin/sde-status page paginates the
 * full history and filters via {@see self::scopeBumps()}.
 *
 * @property int $id
 * @property \Illuminate\Support\Carbon $checked_at
 * @property string|null $pinned_version
 * @property string|null $upstream_version
 * @property string|null $upstream_etag
 * @property string|null $upstream_last_modified
 * @property bool $is_bump_available
 * @property int|null $http_status
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $created_at
 */
class SdeVersionCheck extends Model
{
    protected $table = 'sde_version_checks';

    // Append-only: created_at is DB-managed; updated_at doesn't exist.
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'checked_at' => 'datetime',
        'created_at' => 'datetime',
        'is_bump_available' => 'boolean',
        'http_status' => 'integer',
    ];

    /**
     * Most recent check, regardless of outcome. Used by the widget to
     * answer "what's the current status?".
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderByDesc('checked_at')->orderByDesc('id');
    }

    /**
     * Only checks that detected a bump. Used by the history page's
     * "bumps only" filter.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBumps(Builder $query): Builder
    {
        return $query->where('is_bump_available', true);
    }
}

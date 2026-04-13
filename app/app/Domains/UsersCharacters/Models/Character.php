<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An EVE character mirrored into AegisCore.
 *
 * Phase 1 rows come from `App\Services\Eve\Sso\EveSsoClient` during
 * /auth/eve/callback — we upsert on `character_id` and link `user_id`.
 * `corporation_id` + `alliance_id` are filled later by the phase-2
 * character-affiliation poller.
 *
 * @property int         $id
 * @property int         $character_id   EVE's permanent character ID.
 * @property string      $name
 * @property int|null    $corporation_id Filled in phase 2 from ESI.
 * @property int|null    $alliance_id    Filled in phase 2 from ESI.
 * @property int|null    $user_id
 */
class Character extends Model
{
    protected $table = 'characters';

    protected $fillable = [
        'character_id',
        'name',
        'corporation_id',
        'alliance_id',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'character_id' => 'integer',
            'corporation_id' => 'integer',
            'alliance_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

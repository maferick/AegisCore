<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spec 6 silent FC attestation row — user believes pilot X led sub-fleet Y
 * in battle Z. Stored append-only; latest wins at Spec 7 read time.
 *
 * Mode A: never displayed to any user other than the submitter.
 *
 * @property int $attestation_id
 * @property int $battle_id
 * @property int $alliance_id
 * @property int $sub_fleet_id
 * @property int $partition_algo_version
 * @property int $attested_character_id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $attested_at
 * @property string|null $user_note
 */
class BattleFcUserAttestation extends Model
{
    protected $table = 'battle_fc_user_attestations';

    protected $primaryKey = 'attestation_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'attested_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

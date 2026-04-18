<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Services;

use App\Domains\KillmailsBattleTheaters\Models\BattleFcUserAttestation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Records silent FC attestations from donor-tier users.
 *
 * Append-only: every call inserts a new row. Spec 7 consumers read the
 * latest attestation per (battle, alliance, sub_fleet, user) tuple.
 *
 * Validation:
 *   - user must be a donor (paid tier; enforced server-side, never
 *     trusted from client)
 *   - sub-fleet must exist for the given (battle, alliance, version)
 *   - attested character must appear in
 *     battle_character_sub_fleet_membership for this battle/alliance
 *     (any sub-fleet on the same side is acceptable — see Spec 6 §6)
 */
final class BattleFcAttestationRecorder
{
    public function record(
        User $user,
        int $battleId,
        int $allianceId,
        int $subFleetId,
        int $partitionAlgoVersion,
        int $attestedCharacterId,
        ?string $userNote = null,
    ): BattleFcUserAttestation {
        if (! $user->isDonor()) {
            throw new RuntimeException('FC attestations are a donor-tier feature.');
        }

        $subFleetExists = DB::table('battle_sub_fleets')
            ->where('battle_id', $battleId)
            ->where('alliance_id', $allianceId)
            ->where('sub_fleet_id', $subFleetId)
            ->where('partition_algo_version', $partitionAlgoVersion)
            ->exists();
        if (! $subFleetExists) {
            throw new RuntimeException(
                "Sub-fleet not found for battle={$battleId} alliance={$allianceId} sub_fleet={$subFleetId} partition_version={$partitionAlgoVersion}",
            );
        }

        $onSide = DB::table('battle_character_sub_fleet_membership')
            ->where('battle_id', $battleId)
            ->where('alliance_id', $allianceId)
            ->where('character_id', $attestedCharacterId)
            ->where('partition_algo_version', $partitionAlgoVersion)
            ->exists();
        if (! $onSide) {
            throw new RuntimeException(
                "Character {$attestedCharacterId} did not participate on alliance {$allianceId} in battle {$battleId}.",
            );
        }

        $note = $userNote === null ? null : mb_substr(trim($userNote), 0, 255);
        if ($note === '') {
            $note = null;
        }

        $row = BattleFcUserAttestation::create([
            'battle_id' => $battleId,
            'alliance_id' => $allianceId,
            'sub_fleet_id' => $subFleetId,
            'partition_algo_version' => $partitionAlgoVersion,
            'attested_character_id' => $attestedCharacterId,
            'user_id' => $user->id,
            'user_note' => $note,
        ]);

        // Log the submission — NOT the attested character (privacy per Spec 6
        // Implementation note 7).
        Log::info('fc_attestation.recorded', [
            'attestation_id' => $row->attestation_id,
            'battle_id' => $battleId,
            'alliance_id' => $allianceId,
            'sub_fleet_id' => $subFleetId,
            'user_id' => $user->id,
        ]);

        return $row;
    }

    /**
     * Read back a user's own attestations, latest first.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function listForUser(User $user, int $limit = 50): \Illuminate\Support\Collection
    {
        return DB::table('battle_fc_user_attestations AS a')
            ->leftJoin('battle_theaters AS bt', 'bt.id', '=', 'a.battle_id')
            ->leftJoin('esi_entity_names AS en', function ($j) {
                $j->on('en.entity_id', '=', 'a.attested_character_id')
                  ->where('en.category', '=', 'character');
            })
            ->leftJoin('esi_entity_names AS al', function ($j) {
                $j->on('al.entity_id', '=', 'a.alliance_id')
                  ->where('al.category', '=', 'alliance');
            })
            ->where('a.user_id', $user->id)
            ->orderByDesc('a.attested_at')
            ->limit($limit)
            ->select(
                'a.attestation_id', 'a.battle_id', 'a.alliance_id', 'a.sub_fleet_id',
                'a.partition_algo_version', 'a.attested_character_id', 'a.attested_at',
                'a.user_note',
                'bt.public_slug', 'bt.start_time',
                'en.name AS attested_character_name',
                'al.name AS alliance_name',
            )
            ->get();
    }
}

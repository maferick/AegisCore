<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Services;

use Illuminate\Support\Facades\DB;

/**
 * Loads Spec 5 role inference + sub-fleet rosters for display on
 * battle reports. Pure read layer — attestation writes live in
 * BattleFcUserAttestationRecorder.
 *
 * The default weight version consumed for display is
 * `v0_scoring_seed` (Spec 5 v0). When Spec 7 lands calibrated weights,
 * flipping the label (or setting is_default=1 on a new row) is the
 * only change needed here.
 */
final class BattleRoleInferenceLoader
{
    public const DEFAULT_WEIGHT_LABEL = 'v0_scoring_seed';

    /**
     * Returns a nested structure keyed by alliance_id → sub_fleet_id →
     * {members, roles, char_names}. Each sub-fleet lists its member
     * roster (with primary ship + category) and inferred roles (fc,
     * logi array, mainline). Sub-fleets with no confident assignment
     * for a role surface null for that role.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function load(int $battleId, array $allianceIds): array
    {
        if ($allianceIds === []) {
            return [];
        }

        $weightVersion = $this->resolveWeightVersion();
        if ($weightVersion === null) {
            return [];
        }

        // Sub-fleet headers.
        $sfRows = DB::table('battle_sub_fleets')
            ->whereIn('alliance_id', $allianceIds)
            ->where('battle_id', $battleId)
            ->get();

        // Membership with per-char primary ship.
        $members = DB::table('battle_character_sub_fleet_membership AS m')
            ->leftJoin('battle_character_role_features AS f', function ($j) {
                $j->on('f.battle_id', '=', 'm.battle_id')
                  ->on('f.alliance_id', '=', 'm.alliance_id')
                  ->on('f.sub_fleet_id', '=', 'm.sub_fleet_id')
                  ->on('f.character_id', '=', 'm.character_id')
                  ->on('f.partition_algo_version', '=', 'm.partition_algo_version');
            })
            ->leftJoin('ref_item_types AS rit', 'rit.id', '=', 'f.ship_type_id')
            ->leftJoin('esi_entity_names AS en', function ($j) {
                $j->on('en.entity_id', '=', 'm.character_id')
                  ->where('en.category', '=', 'character');
            })
            ->where('m.battle_id', $battleId)
            ->whereIn('m.alliance_id', $allianceIds)
            ->select(
                'm.alliance_id', 'm.sub_fleet_id', 'm.partition_algo_version', 'm.character_id',
                'f.ship_class_category', 'rit.name AS ship_name',
                'en.name AS character_name',
            )
            ->get();

        // Inference rows for the active weight_version.
        $inf = DB::table('battle_character_role_inference AS i')
            ->leftJoin('esi_entity_names AS en', function ($j) {
                $j->on('en.entity_id', '=', 'i.character_id')
                  ->where('en.category', '=', 'character');
            })
            ->where('i.battle_id', $battleId)
            ->whereIn('i.alliance_id', $allianceIds)
            ->where('i.weight_version', $weightVersion)
            ->select(
                'i.alliance_id', 'i.sub_fleet_id', 'i.character_id',
                'i.primary_role_key', 'i.primary_score', 'i.confidence',
                'i.confidence_band', 'en.name AS character_name',
            )
            ->get();

        // Index: sub-fleet headers by (alliance, sub_fleet)
        $byAllySf = [];
        foreach ($sfRows as $sf) {
            $byAllySf[(int) $sf->alliance_id][(int) $sf->sub_fleet_id] = [
                'sub_fleet_id' => (int) $sf->sub_fleet_id,
                'partition_algo_version' => (int) $sf->partition_algo_version,
                'member_count' => (int) $sf->member_count,
                'absorbed_orphan_count' => (int) $sf->absorbed_orphan_count,
                'members' => [],
                'roles' => [
                    'fc' => null,
                    'logi' => [],
                    'mainline_dps' => null,
                ],
            ];
        }

        foreach ($members as $m) {
            $aid = (int) $m->alliance_id;
            $sfid = (int) $m->sub_fleet_id;
            if (! isset($byAllySf[$aid][$sfid])) {
                continue;
            }
            $byAllySf[$aid][$sfid]['members'][] = [
                'character_id' => (int) $m->character_id,
                'character_name' => $m->character_name ?? ('char_' . $m->character_id),
                'ship_name' => $m->ship_name,
                'ship_class_category' => $m->ship_class_category,
            ];
        }

        foreach ($inf as $i) {
            $aid = (int) $i->alliance_id;
            $sfid = (int) $i->sub_fleet_id;
            if (! isset($byAllySf[$aid][$sfid])) {
                continue;
            }
            $payload = [
                'character_id' => (int) $i->character_id,
                'character_name' => $i->character_name ?? ('char_' . $i->character_id),
                'primary_score' => (float) $i->primary_score,
                'confidence' => (float) $i->confidence,
                'confidence_band' => (string) $i->confidence_band,
            ];
            $role = (string) $i->primary_role_key;
            if ($role === 'logi') {
                $byAllySf[$aid][$sfid]['roles']['logi'][] = $payload;
            } elseif ($role === 'fc' || $role === 'mainline_dps') {
                $byAllySf[$aid][$sfid]['roles'][$role] = $payload;
            }
        }

        return $byAllySf;
    }

    public function resolveWeightVersion(?string $label = null): ?int
    {
        $row = DB::table('battle_role_weight_versions')
            ->where('label', $label ?? self::DEFAULT_WEIGHT_LABEL)
            ->first();
        return $row ? (int) $row->weight_version : null;
    }

    /**
     * Flat character_id → role_key map for inline badge rendering
     * ("FC" / "Logi" / "DPS" pills next to pilot names in rosters +
     * top-damage lists). Built from the active weight_version's
     * inference rows.
     *
     * @return array<int, string>
     */
    public function charRoleMap(int $battleId, array $allianceIds): array
    {
        if ($allianceIds === []) {
            return [];
        }
        $weightVersion = $this->resolveWeightVersion();
        if ($weightVersion === null) {
            return [];
        }
        return DB::table('battle_character_role_inference')
            ->where('battle_id', $battleId)
            ->whereIn('alliance_id', $allianceIds)
            ->where('weight_version', $weightVersion)
            ->pluck('primary_role_key', 'character_id')
            ->map(fn ($v) => (string) $v)
            ->all();
    }
}

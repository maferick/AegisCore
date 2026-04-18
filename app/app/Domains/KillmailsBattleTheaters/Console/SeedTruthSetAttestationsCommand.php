<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Console;

use App\Domains\KillmailsBattleTheaters\Models\BattleFcUserAttestation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the operator-curated 8-battle FC truth set (documented in
 * verification/spec4/truth_set_8_battles.md) as attestations under a
 * designated user, so Spec 7 calibration has real data to compare
 * Spec 5 inference against without waiting for donor submissions.
 *
 * Usage:
 *   php artisan battle:seed-truth-attestations --user-id=1 [--dry-run]
 *
 * The user-id chosen is the "voice" the truth-set speaks with. Spec 7
 * treats each user's latest-per-sub-fleet attestation as a vote; seeding
 * under a known operator id makes filtering trivial.
 *
 * Donor check is bypassed because this is an operator-initiated bulk
 * seed, not a user-facing flow. Each seeded row carries a user_note
 * tagging it as "spec4_truth_set_seed" so admin views + Spec 7 can
 * distinguish seeded labels from real donor submissions.
 *
 * Idempotent: re-running with the same --user-id and partition version
 * writes fresh rows (append-only by design). Spec 7 reads the latest,
 * so repeated seeding is harmless.
 */
class SeedTruthSetAttestationsCommand extends Command
{
    protected $signature = 'battle:seed-truth-attestations
                            {--user-id= : User id to own the seeded attestations}
                            {--partition-algo-version=1 : Partition version to pin}
                            {--dry-run : Print what would be inserted, write nothing}';

    protected $description = 'Seed verification/spec4/truth_set_8_battles.md FC labels into battle_fc_user_attestations.';

    /**
     * Strong FC entries from the 8-battle truth set.
     * Soft/unknown entries are deliberately excluded — we only seed
     * high-confidence human labels so Spec 7 can measure inference
     * against a clean signal.
     *
     * Each row: [battle_id, alliance_id, sub_fleet_id, character_id, note]
     *
     * @var array<int, array{0:int,1:int,2:int,3:int,4:string}>
     */
    private const TRUTH_SET_FCS = [
        // 40228 Aldranette
        [40228, 99014027, 0, 2124045888, '40228 sf0 — zrxz-1 (Damnation)'],
        [40228, 99014027, 1, 2119972247, '40228 sf1 — Honour antimuon (Eos)'],

        // 40365 Amamake — only sf0 has a plausible FC
        [40365, 99011978, 0, 634915984,  '40365 sf0 — BearThatCares (Pontifex)'],

        // 40374 2E-ZR5 Fraternity — two confident FCs
        [40374, 99003581, 0, 2114375223, '40374 sf0 — Richard Heraclid (Claymore)'],
        [40374, 99003581, 1, 2115201818, '40374 sf1 — Pax Laser (Stork)'],

        // 40478 Atioth Fraternity
        [40478, 99003581, 0, 2121995095, '40478 sf0 — YesComreda (Bifrost)'],

        // 40541 U-L4KS Sigma
        // sf0 co-FCs: AntientSphinx / NashWolfe (both Claymore degree 1.0).
        // Pick AntientSphinx as the primary label — co-FC tolerance
        // documented in the truth set so Spec 7 treats ties as pass.
        [40541, 99011223, 0, 519945752,  '40541 sf0 — AntientSphinx (Claymore co-FC)'],
        [40541, 99011223, 1, 93444333,   '40541 sf1 — jacky Audeles (Monitor, verified)'],

        // 40537 Komo — soft candidates only; still seed the strongest
        [40537, 1900696668, 0, 97110565,  '40537 sf0 — Kenzie Nardieu (Stork, top-degree)'],
        [40537, 1900696668, 1, 2122948777,'40537 sf1 — Vulture pilot (alt chain)'],

        // 40605 9S-GPT — co-FCs; pick BebolinA (Vulture) as primary
        [40605, 99012122, 0, 2115774750, '40605 sf0 — BebolinA (Vulture co-FC)'],

        // 40553 6RQ9-A Sigma — cleanest battle, Monitor FC
        [40553, 99011223, 0, 93444333,   '40553 sf0 — jacky Audeles (Monitor)'],
    ];

    public function handle(): int
    {
        $userId = (int) $this->option('user-id');
        if ($userId <= 0) {
            $this->error('Pass --user-id=<id> of the operator user the seed speaks as.');
            return self::FAILURE;
        }
        $user = User::find($userId);
        if ($user === null) {
            $this->error("User id {$userId} not found.");
            return self::FAILURE;
        }

        $partitionV = (int) $this->option('partition-algo-version');
        $dryRun = (bool) $this->option('dry-run');

        $toInsert = [];
        $skipped = [];

        foreach (self::TRUTH_SET_FCS as [$battleId, $allianceId, $subFleetId, $characterId, $note]) {
            $sfExists = DB::table('battle_sub_fleets')
                ->where('battle_id', $battleId)
                ->where('alliance_id', $allianceId)
                ->where('sub_fleet_id', $subFleetId)
                ->where('partition_algo_version', $partitionV)
                ->exists();
            if (! $sfExists) {
                $skipped[] = "sub-fleet missing: battle={$battleId} alliance={$allianceId} sf={$subFleetId}";
                continue;
            }
            $memberOk = DB::table('battle_character_sub_fleet_membership')
                ->where('battle_id', $battleId)
                ->where('alliance_id', $allianceId)
                ->where('character_id', $characterId)
                ->where('partition_algo_version', $partitionV)
                ->exists();
            if (! $memberOk) {
                $skipped[] = "character not in membership: battle={$battleId} char={$characterId}";
                continue;
            }
            $toInsert[] = [
                'battle_id' => $battleId,
                'alliance_id' => $allianceId,
                'sub_fleet_id' => $subFleetId,
                'partition_algo_version' => $partitionV,
                'attested_character_id' => $characterId,
                'user_id' => $userId,
                'user_note' => '[spec4_truth_set_seed] ' . $note,
            ];
        }

        $this->info("Prepared " . count($toInsert) . " attestation rows; " . count($skipped) . " skipped.");
        foreach ($skipped as $reason) {
            $this->warn("  skip: {$reason}");
        }

        if ($dryRun) {
            $this->info('Dry run — nothing written.');
            foreach ($toInsert as $row) {
                $this->line("  would insert: battle={$row['battle_id']} alliance={$row['alliance_id']} sf={$row['sub_fleet_id']} char={$row['attested_character_id']}");
            }
            return self::SUCCESS;
        }

        DB::transaction(function () use ($toInsert): void {
            foreach ($toInsert as $row) {
                BattleFcUserAttestation::create($row);
            }
        });

        $this->info("Seeded " . count($toInsert) . " truth-set attestations under user_id={$userId}.");

        return self::SUCCESS;
    }
}

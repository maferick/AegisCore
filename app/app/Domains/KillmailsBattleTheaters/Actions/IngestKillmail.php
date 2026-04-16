<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Actions;

use App\Domains\KillmailsBattleTheaters\Data\IngestKillmailResult;
use App\Domains\KillmailsBattleTheaters\Events\KillmailIngested;
use App\Domains\KillmailsBattleTheaters\Models\Killmail;
use App\Domains\KillmailsBattleTheaters\Models\KillmailAttacker;
use App\Domains\KillmailsBattleTheaters\Models\KillmailItem;
use App\Outbox\OutboxRecorder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for accepting a raw ESI killmail into MariaDB.
 *
 * Accepts a verbatim ESI `/killmails/{id}/{hash}/` payload, persists
 * the killmail + attackers + items idempotently, and emits the
 * `killmail.ingested` outbox event for downstream consumers.
 *
 * Idempotent: the same killmail_id can be ingested multiple times
 * safely. Killmail row is upserted; attackers and items are replaced
 * (delete + re-insert) since the ESI payload is the full truth.
 *
 * Enrichment (valuation, classification, name resolution) is NOT
 * performed here — that happens downstream in {@see EnrichKillmail}.
 * Location hierarchy (constellation_id, region_id) is set to 0 at
 * ingestion and resolved during enrichment.
 */
final class IngestKillmail
{
    public function __construct(
        private readonly OutboxRecorder $outboxRecorder,
    ) {}

    /**
     * @param  array<string, mixed>  $esiPayload  Verbatim ESI killmail response.
     */
    public function handle(array $esiPayload): IngestKillmailResult
    {
        $killmailId = (int) $esiPayload['killmail_id'];
        $killmailHash = (string) ($esiPayload['killmail_hash'] ?? '');
        $solarSystemId = (int) ($esiPayload['solar_system_id'] ?? 0);
        $killedAt = Carbon::parse($esiPayload['killmail_time']);
        $warId = isset($esiPayload['war_id']) ? (int) $esiPayload['war_id'] : null;

        $victim = $esiPayload['victim'] ?? [];
        $esiAttackers = $esiPayload['attackers'] ?? [];
        $esiItems = $victim['items'] ?? [];

        // Derive metadata.
        $attackerCount = count($esiAttackers);
        $playerAttackerCount = 0;

        foreach ($esiAttackers as $att) {
            if (! empty($att['character_id'])) {
                $playerAttackerCount++;
            }
        }

        $isNpcKill = $playerAttackerCount === 0;
        $isSoloKill = $playerAttackerCount === 1;

        $wasNew = false;
        $itemCount = 0;

        DB::transaction(function () use (
            $killmailId, $killmailHash, $solarSystemId, $killedAt, $warId,
            $victim, $esiAttackers, $esiItems,
            $attackerCount, $isNpcKill, $isSoloKill,
            &$wasNew, &$itemCount,
        ): void {
            // 1. Upsert killmail (idempotent on natural PK).
            $killmail = Killmail::updateOrCreate(
                ['killmail_id' => $killmailId],
                [
                    'killmail_hash' => $killmailHash,
                    'solar_system_id' => $solarSystemId,
                    'constellation_id' => 0,
                    'region_id' => 0,
                    'killed_at' => $killedAt,
                    'victim_character_id' => ! empty($victim['character_id']) ? (int) $victim['character_id'] : null,
                    'victim_corporation_id' => ! empty($victim['corporation_id']) ? (int) $victim['corporation_id'] : null,
                    'victim_alliance_id' => ! empty($victim['alliance_id']) ? (int) $victim['alliance_id'] : null,
                    'victim_ship_type_id' => (int) ($victim['ship_type_id'] ?? 0),
                    'victim_damage_taken' => (int) ($victim['damage_taken'] ?? 0),
                    'attacker_count' => $attackerCount,
                    'is_npc_kill' => $isNpcKill,
                    'is_solo_kill' => $isSoloKill,
                    'war_id' => $warId,
                    'ingested_at' => now(),
                ],
            );

            $wasNew = $killmail->wasRecentlyCreated;

            // 2. Replace attackers (ESI payload is the full truth).
            KillmailAttacker::where('killmail_id', $killmailId)->delete();

            foreach ($esiAttackers as $att) {
                KillmailAttacker::create([
                    'killmail_id' => $killmailId,
                    'character_id' => ! empty($att['character_id']) ? (int) $att['character_id'] : null,
                    'corporation_id' => ! empty($att['corporation_id']) ? (int) $att['corporation_id'] : null,
                    'alliance_id' => ! empty($att['alliance_id']) ? (int) $att['alliance_id'] : null,
                    'faction_id' => ! empty($att['faction_id']) ? (int) $att['faction_id'] : null,
                    'ship_type_id' => ! empty($att['ship_type_id']) ? (int) $att['ship_type_id'] : null,
                    'weapon_type_id' => ! empty($att['weapon_type_id']) ? (int) $att['weapon_type_id'] : null,
                    'damage_done' => (int) ($att['damage_done'] ?? 0),
                    'is_final_blow' => (bool) ($att['final_blow'] ?? false),
                    'security_status' => isset($att['security_status']) ? round((float) $att['security_status'], 1) : null,
                ]);
            }

            // 3. Replace items.
            KillmailItem::where('killmail_id', $killmailId)->delete();

            foreach ($esiItems as $item) {
                $flag = (int) ($item['flag'] ?? 0);

                KillmailItem::create([
                    'killmail_id' => $killmailId,
                    'type_id' => (int) ($item['item_type_id'] ?? 0),
                    'flag' => $flag,
                    'quantity_destroyed' => (int) ($item['quantity_destroyed'] ?? 0),
                    'quantity_dropped' => (int) ($item['quantity_dropped'] ?? 0),
                    'singleton' => (int) ($item['singleton'] ?? 0),
                    'slot_category' => KillmailItem::slotCategoryFromFlag($flag),
                ]);
            }

            $itemCount = count($esiItems);

            // 4. Emit outbox event.
            $attackerCharacterIds = [];
            foreach ($esiAttackers as $att) {
                if (! empty($att['character_id'])) {
                    $attackerCharacterIds[] = (int) $att['character_id'];
                }
            }

            $this->outboxRecorder->record(new KillmailIngested(
                killmailId: $killmailId,
                killmailHash: $killmailHash,
                solarSystemId: $solarSystemId,
                regionId: 0,
                victimCharacterId: ! empty($victim['character_id']) ? (int) $victim['character_id'] : null,
                victimCorporationId: ! empty($victim['corporation_id']) ? (int) $victim['corporation_id'] : null,
                victimAllianceId: ! empty($victim['alliance_id']) ? (int) $victim['alliance_id'] : null,
                victimShipTypeId: (int) ($victim['ship_type_id'] ?? 0),
                attackerCharacterIds: $attackerCharacterIds,
                totalValue: '0.00',
                attackerCount: $attackerCount,
                killedAt: $killedAt->toIso8601String(),
            ));
        });

        return new IngestKillmailResult(
            killmailId: $killmailId,
            wasNew: $wasNew,
            attackerCount: $attackerCount,
            itemCount: $itemCount,
        );
    }
}

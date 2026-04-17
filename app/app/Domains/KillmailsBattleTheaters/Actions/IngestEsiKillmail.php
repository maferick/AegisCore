<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Actions;

use App\Domains\KillmailsBattleTheaters\Events\KillmailIngested;
use App\Domains\KillmailsBattleTheaters\Models\KillmailItem;
use App\Outbox\OutboxRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Take a verbatim ESI killmail payload plus its hash, upsert the
 * killmail + attackers + items rows inside a single transaction,
 * and emit the `killmail.ingested` outbox event.
 *
 * Mirrors the Python `ingest_killmail` write path so rows landed
 * via the PHP catch-up job are bit-for-bit identical to the ones
 * the stream writes. ON DUPLICATE KEY UPDATE means re-ingesting an
 * existing killmail_id is a safe no-op (only the updated_at bump).
 */
final class IngestEsiKillmail
{
    public function __construct(
        private readonly OutboxRecorder $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $esi  Verbatim ESI killmail body
     * @return array{new: bool, event_id: ?string}
     */
    public function handle(array $esi, string $killmailHash): array
    {
        $killmailId = (int) ($esi['killmail_id'] ?? 0);
        if ($killmailId <= 0) {
            return ['new' => false, 'event_id' => null];
        }

        $victim = $esi['victim'] ?? [];
        $attackers = $esi['attackers'] ?? [];
        $items = $victim['items'] ?? [];

        $killedAt = Carbon::parse($esi['killmail_time'] ?? 'now');

        $playerAttackerCount = 0;
        foreach ($attackers as $a) {
            if (! empty($a['character_id'])) {
                $playerAttackerCount++;
            }
        }

        return DB::transaction(function () use (
            $esi, $killmailId, $killmailHash, $victim, $attackers, $items, $killedAt, $playerAttackerCount,
        ) {
            $now = now();
            $solarSystemId = (int) ($esi['solar_system_id'] ?? 0);

            $rowsAffected = DB::insert(
                <<<'SQL'
                INSERT INTO killmails
                    (killmail_id, killmail_hash, solar_system_id, constellation_id,
                     region_id, killed_at, victim_character_id, victim_corporation_id,
                     victim_alliance_id, victim_ship_type_id, victim_damage_taken,
                     attacker_count, is_npc_kill, is_solo_kill, war_id,
                     ingested_at, created_at, updated_at)
                VALUES (?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    killmail_hash = VALUES(killmail_hash),
                    solar_system_id = VALUES(solar_system_id),
                    victim_character_id = VALUES(victim_character_id),
                    victim_corporation_id = VALUES(victim_corporation_id),
                    victim_alliance_id = VALUES(victim_alliance_id),
                    victim_ship_type_id = VALUES(victim_ship_type_id),
                    victim_damage_taken = VALUES(victim_damage_taken),
                    attacker_count = VALUES(attacker_count),
                    is_npc_kill = VALUES(is_npc_kill),
                    is_solo_kill = VALUES(is_solo_kill),
                    war_id = VALUES(war_id),
                    updated_at = VALUES(updated_at)
                SQL,
                [
                    $killmailId, $killmailHash, $solarSystemId,
                    $killedAt->toDateTimeString(),
                    $victim['character_id'] ?? null,
                    $victim['corporation_id'] ?? null,
                    $victim['alliance_id'] ?? null,
                    (int) ($victim['ship_type_id'] ?? 0),
                    (int) ($victim['damage_taken'] ?? 0),
                    count($attackers),
                    $playerAttackerCount === 0 ? 1 : 0,
                    $playerAttackerCount === 1 ? 1 : 0,
                    $esi['war_id'] ?? null,
                    $now, $now, $now,
                ],
            );

            DB::table('killmail_attackers')->where('killmail_id', $killmailId)->delete();
            if ($attackers !== []) {
                $rows = [];
                foreach ($attackers as $a) {
                    $rows[] = [
                        'killmail_id' => $killmailId,
                        'character_id' => $a['character_id'] ?? null,
                        'corporation_id' => $a['corporation_id'] ?? null,
                        'alliance_id' => $a['alliance_id'] ?? null,
                        'faction_id' => $a['faction_id'] ?? null,
                        'ship_type_id' => $a['ship_type_id'] ?? null,
                        'weapon_type_id' => $a['weapon_type_id'] ?? null,
                        'damage_done' => (int) ($a['damage_done'] ?? 0),
                        'is_final_blow' => ! empty($a['final_blow']) ? 1 : 0,
                        'security_status' => $a['security_status'] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('killmail_attackers')->insert($chunk);
                }
            }

            DB::table('killmail_items')->where('killmail_id', $killmailId)->delete();
            if ($items !== []) {
                $rows = [];
                foreach ($items as $item) {
                    $flag = (int) ($item['flag'] ?? 0);
                    $rows[] = [
                        'killmail_id' => $killmailId,
                        'type_id' => (int) ($item['item_type_id'] ?? 0),
                        'flag' => $flag,
                        'quantity_destroyed' => (int) ($item['quantity_destroyed'] ?? 0),
                        'quantity_dropped' => (int) ($item['quantity_dropped'] ?? 0),
                        'singleton' => (int) ($item['singleton'] ?? 0),
                        'slot_category' => KillmailItem::slotCategoryFromFlag($flag),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('killmail_items')->insert($chunk);
                }
            }

            $attackerCharacterIds = [];
            foreach ($attackers as $a) {
                if (! empty($a['character_id'])) {
                    $attackerCharacterIds[] = (int) $a['character_id'];
                }
            }

            $event = new KillmailIngested(
                killmailId: $killmailId,
                killmailHash: $killmailHash,
                solarSystemId: $solarSystemId,
                regionId: 0,
                victimCharacterId: isset($victim['character_id']) ? (int) $victim['character_id'] : null,
                victimCorporationId: isset($victim['corporation_id']) ? (int) $victim['corporation_id'] : null,
                victimAllianceId: isset($victim['alliance_id']) ? (int) $victim['alliance_id'] : null,
                victimShipTypeId: (int) ($victim['ship_type_id'] ?? 0),
                attackerCharacterIds: $attackerCharacterIds,
                totalValue: '0.00',
                attackerCount: count($attackers),
                killedAt: $killedAt->toIso8601ZuluString(),
            );
            $recorded = $this->outbox->record($event);

            return [
                'new' => $rowsAffected === 1,
                'event_id' => (string) $recorded->event_id,
            ];
        });
    }
}

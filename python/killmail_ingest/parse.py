"""Parse ESI killmail JSON into normalised dataclasses.

The flag-to-slot mapping MUST exactly replicate
KillmailItem::slotCategoryFromFlag() in PHP
(app/Domains/KillmailsBattleTheaters/Models/KillmailItem.php).
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime, timezone


@dataclass(frozen=True)
class ParsedAttacker:
    character_id: int | None
    corporation_id: int | None
    alliance_id: int | None
    faction_id: int | None
    ship_type_id: int | None
    weapon_type_id: int | None
    damage_done: int
    is_final_blow: bool
    security_status: float | None


@dataclass(frozen=True)
class ParsedItem:
    type_id: int
    flag: int
    quantity_destroyed: int
    quantity_dropped: int
    singleton: int
    slot_category: str


@dataclass(frozen=True)
class ParsedKillmail:
    killmail_id: int
    killmail_hash: str
    solar_system_id: int
    killed_at: datetime
    victim_character_id: int | None
    victim_corporation_id: int | None
    victim_alliance_id: int | None
    victim_ship_type_id: int
    victim_damage_taken: int
    war_id: int | None
    attackers: list[ParsedAttacker] = field(default_factory=list)
    items: list[ParsedItem] = field(default_factory=list)
    attacker_count: int = 0
    is_npc_kill: bool = False
    is_solo_kill: bool = False


def slot_category_from_flag(flag: int) -> str:
    """Map CCP inventory flag to normalised slot category.

    Must match KillmailItem::slotCategoryFromFlag() in PHP exactly.
    """
    if 27 <= flag <= 34:
        return "high"
    if 19 <= flag <= 26:
        return "mid"
    if 11 <= flag <= 18:
        return "low"
    if 92 <= flag <= 99:
        return "rig"
    if 125 <= flag <= 132:
        return "subsystem"
    if 164 <= flag <= 171:
        return "service"
    if flag == 87:
        return "drone_bay"
    if flag == 158:
        return "fighter_bay"
    if flag == 89:
        return "implant"
    if flag in (0, 5, 62, 90) or 133 <= flag <= 156:
        return "cargo"
    return "other"


def parse_esi_killmail(raw: dict, killmail_hash: str = "") -> ParsedKillmail:
    """Parse a verbatim ESI killmail payload into a ParsedKillmail.

    ``killmail_hash`` is passed separately because EVE Ref archives
    embed it in the filename, not the JSON body. R2Z2 includes it in
    the wrapper's ``zkb.hash`` field.
    """
    victim = raw.get("victim") or {}
    esi_attackers = raw.get("attackers") or []
    esi_items = victim.get("items") or []

    attackers = [
        ParsedAttacker(
            character_id=att.get("character_id"),
            corporation_id=att.get("corporation_id"),
            alliance_id=att.get("alliance_id"),
            faction_id=att.get("faction_id"),
            ship_type_id=att.get("ship_type_id"),
            weapon_type_id=att.get("weapon_type_id"),
            damage_done=int(att.get("damage_done", 0)),
            is_final_blow=bool(att.get("final_blow", False)),
            security_status=att.get("security_status"),
        )
        for att in esi_attackers
    ]

    items = [
        ParsedItem(
            type_id=int(item.get("item_type_id", 0)),
            flag=int(item.get("flag", 0)),
            quantity_destroyed=int(item.get("quantity_destroyed", 0)),
            quantity_dropped=int(item.get("quantity_dropped", 0)),
            singleton=int(item.get("singleton", 0)),
            slot_category=slot_category_from_flag(int(item.get("flag", 0))),
        )
        for item in esi_items
    ]

    player_attacker_count = sum(1 for a in attackers if a.character_id)

    # Parse killed_at — ESI uses ISO-8601 with trailing Z.
    killed_at_raw = raw.get("killmail_time", "")
    if killed_at_raw.endswith("Z"):
        killed_at_raw = killed_at_raw[:-1] + "+00:00"
    killed_at = datetime.fromisoformat(killed_at_raw) if killed_at_raw else datetime.now(timezone.utc)

    return ParsedKillmail(
        killmail_id=int(raw["killmail_id"]),
        killmail_hash=killmail_hash or raw.get("killmail_hash", ""),
        solar_system_id=int(raw.get("solar_system_id", 0)),
        killed_at=killed_at,
        victim_character_id=victim.get("character_id"),
        victim_corporation_id=victim.get("corporation_id"),
        victim_alliance_id=victim.get("alliance_id"),
        victim_ship_type_id=int(victim.get("ship_type_id", 0)),
        victim_damage_taken=int(victim.get("damage_taken", 0)),
        war_id=raw.get("war_id"),
        attackers=attackers,
        items=items,
        attacker_count=len(attackers),
        is_npc_kill=player_attacker_count == 0,
        is_solo_kill=player_attacker_count == 1,
    )

"""Declarative mapping: SDE JSONL file → ref_* table → column extraction.

One TableSpec per JSONL file in the SDE zip. The generic loader
(`loader.py`) walks these specs, streams each file, and bulk-inserts into
the matching `ref_*` table using `column_map` to extract typed columns
and `overflow_column` to preserve the original row as JSON.

Adding a new table is a data-only change here — no loader code edits
required, provided the types fit {int, float, str, bool, dict/list→json}.
"""

from __future__ import annotations

from dataclasses import dataclass, field


# Column extraction kinds used by loader.extract_value():
#   int, bigint   — cast to int
#   float         — cast to float
#   str           — cast to str (truncated if needed)
#   bool          — cast to bool
#   name          — if dict, take .en (i18n); else cast to str
#   json          — json-serialize the value (for list/dict fields)
#   overflow      — json-serialize the full row (catches unknown fields)
#
# Paths are dotted: "position.x" → row["position"]["x"]. Missing
# intermediate keys yield None.


@dataclass(frozen=True)
class Column:
    name: str               # target column in ref_* table
    source: str             # dotted path into the JSONL row, or "__row__" for overflow
    kind: str               # one of the kinds above


@dataclass(frozen=True)
class TableSpec:
    file: str               # JSONL filename inside the zip
    table: str              # target MariaDB table
    columns: list[Column]   # left-to-right order is the INSERT order
    pk_kind: str = "int"    # most _keys are int; translationLanguages is str

    def column_names(self) -> list[str]:
        return [c.name for c in self.columns]


def _overflow() -> Column:
    return Column("data", "__row__", "overflow")


# ---------------------------------------------------------------------------
# Table specs. Order matters for the truncate/load pass: leaf tables first
# is conventional, but since we don't enforce FKs it's only cosmetic.
# ---------------------------------------------------------------------------

SPECS: list[TableSpec] = [
    # ============ UNIVERSE =================================================

    TableSpec(
        file="mapRegions.jsonl",
        table="ref_regions",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("faction_id", "factionID", "int"),
            Column("nebula_id", "nebulaID", "int"),
            Column("wormhole_class_id", "wormholeClassID", "int"),
            Column("position_x", "position.x", "float"),
            Column("position_y", "position.y", "float"),
            Column("position_z", "position.z", "float"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="mapConstellations.jsonl",
        table="ref_constellations",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("region_id", "regionID", "int"),
            Column("faction_id", "factionID", "int"),
            Column("wormhole_class_id", "wormholeClassID", "int"),
            Column("position_x", "position.x", "float"),
            Column("position_y", "position.y", "float"),
            Column("position_z", "position.z", "float"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="mapSolarSystems.jsonl",
        table="ref_solar_systems",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("region_id", "regionID", "int"),
            Column("constellation_id", "constellationID", "int"),
            Column("star_id", "starID", "int"),
            Column("security_status", "securityStatus", "float"),
            Column("security_class", "securityClass", "str"),
            Column("hub", "hub", "bool"),
            Column("border", "border", "bool"),
            Column("international", "international", "bool"),
            Column("regional", "regional", "bool"),
            Column("luminosity", "luminosity", "float"),
            Column("radius", "radius", "float"),
            Column("position_x", "position.x", "float"),
            Column("position_y", "position.y", "float"),
            Column("position_z", "position.z", "float"),
            # CCP ships a schematic 2D position (position2D) used by the
            # in-game 2D map. Not every dataset includes it, hence the
            # nullable column pair. See ADR-0001 + map renderer module.
            Column("position2d_x", "position2D.x", "float"),
            Column("position2d_y", "position2D.y", "float"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="mapStargates.jsonl",
        table="ref_stargates",
        columns=[
            Column("id", "_key", "int"),
            Column("solar_system_id", "solarSystemID", "int"),
            Column("destination_system_id", "destination.solarSystemID", "int"),
            Column("destination_stargate_id", "destination.stargateID", "int"),
            Column("type_id", "typeID", "int"),
            Column("position_x", "position.x", "float"),
            Column("position_y", "position.y", "float"),
            Column("position_z", "position.z", "float"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="mapStars.jsonl",
        table="ref_stars",
        columns=[
            Column("id", "_key", "int"),
            Column("solar_system_id", "solarSystemID", "int"),
            Column("type_id", "typeID", "int"),
            Column("radius", "radius", "float"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="mapPlanets.jsonl",
        table="ref_planets",
        columns=[
            Column("id", "_key", "int"),
            Column("solar_system_id", "solarSystemID", "int"),
            Column("type_id", "typeID", "int"),
            Column("celestial_index", "celestialIndex", "int"),
            Column("radius", "radius", "float"),
            Column("position_x", "position.x", "float"),
            Column("position_y", "position.y", "float"),
            Column("position_z", "position.z", "float"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="mapMoons.jsonl",
        table="ref_moons",
        columns=[
            Column("id", "_key", "int"),
            Column("solar_system_id", "solarSystemID", "int"),
            Column("orbit_id", "orbitID", "int"),
            Column("type_id", "typeID", "int"),
            Column("celestial_index", "celestialIndex", "int"),
            Column("orbit_index", "orbitIndex", "int"),
            Column("radius", "radius", "float"),
            Column("position_x", "position.x", "float"),
            Column("position_y", "position.y", "float"),
            Column("position_z", "position.z", "float"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="mapAsteroidBelts.jsonl",
        table="ref_asteroid_belts",
        columns=[
            Column("id", "_key", "int"),
            Column("solar_system_id", "solarSystemID", "int"),
            Column("orbit_id", "orbitID", "int"),
            Column("type_id", "typeID", "int"),
            Column("radius", "radius", "float"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="mapSecondarySuns.jsonl",
        table="ref_secondary_suns",
        columns=[
            Column("id", "_key", "int"),
            Column("solar_system_id", "solarSystemID", "int"),
            Column("type_id", "typeID", "int"),
            Column("effect_beacon_type_id", "effectBeaconTypeID", "int"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="landmarks.jsonl",
        table="ref_landmarks",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("position_x", "position.x", "float"),
            Column("position_y", "position.y", "float"),
            Column("position_z", "position.z", "float"),
            _overflow(),
        ],
    ),

    # ============ ITEMS ====================================================

    TableSpec(
        file="categories.jsonl",
        table="ref_item_categories",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("icon_id", "iconID", "int"),
            Column("published", "published", "bool"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="groups.jsonl",
        table="ref_item_groups",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("category_id", "categoryID", "int"),
            Column("icon_id", "iconID", "int"),
            Column("anchorable", "anchorable", "bool"),
            Column("anchored", "anchored", "bool"),
            Column("fittable_non_singleton", "fittableNonSingleton", "bool"),
            Column("use_base_price", "useBasePrice", "bool"),
            Column("published", "published", "bool"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="marketGroups.jsonl",
        table="ref_market_groups",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("parent_group_id", "parentGroupID", "int"),
            Column("icon_id", "iconID", "int"),
            Column("has_types", "hasTypes", "bool"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="metaGroups.jsonl",
        table="ref_meta_groups",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="types.jsonl",
        table="ref_item_types",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("group_id", "groupID", "int"),
            Column("market_group_id", "marketGroupID", "int"),
            Column("meta_group_id", "metaGroupID", "int"),
            Column("faction_id", "factionID", "int"),
            Column("race_id", "raceID", "int"),
            Column("icon_id", "iconID", "int"),
            Column("graphic_id", "graphicID", "int"),
            Column("sound_id", "soundID", "int"),
            Column("variation_parent_type_id", "variationParentTypeID", "int"),
            Column("meta_level", "metaLevel", "int"),
            Column("base_price", "basePrice", "float"),
            Column("mass", "mass", "float"),
            Column("radius", "radius", "float"),
            Column("volume", "volume", "float"),
            Column("capacity", "capacity", "float"),
            Column("portion_size", "portionSize", "int"),
            Column("published", "published", "bool"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="compressibleTypes.jsonl",
        table="ref_compressible_types",
        columns=[
            Column("id", "_key", "int"),
            Column("compressed_type_id", "compressedTypeID", "int"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="contrabandTypes.jsonl",
        table="ref_contraband_types",
        columns=[
            Column("id", "_key", "int"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="dynamicItemAttributes.jsonl",
        table="ref_dynamic_item_attributes",
        columns=[
            Column("id", "_key", "int"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="typeMaterials.jsonl",
        table="ref_type_materials",
        columns=[
            Column("id", "_key", "int"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="typeDogma.jsonl",
        table="ref_type_dogma",
        columns=[
            Column("id", "_key", "int"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="typeBonus.jsonl",
        table="ref_type_bonus",
        columns=[
            Column("id", "_key", "int"),
            _overflow(),
        ],
    ),

    # ============ DOGMA ====================================================

    TableSpec(
        file="dogmaAttributes.jsonl",
        table="ref_dogma_attributes",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("attribute_category_id", "attributeCategoryID", "int"),
            Column("default_value", "defaultValue", "float"),
            Column("data_type", "dataType", "int"),
            Column("high_is_good", "highIsGood", "bool"),
            Column("stackable", "stackable", "bool"),
            Column("display_when_zero", "displayWhenZero", "bool"),
            Column("published", "published", "bool"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="dogmaEffects.jsonl",
        table="ref_dogma_effects",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("guid", "guid", "str"),
            Column("effect_category_id", "effectCategoryID", "int"),
            Column("discharge_attribute_id", "dischargeAttributeID", "int"),
            Column("duration_attribute_id", "durationAttributeID", "int"),
            Column("is_offensive", "isOffensive", "bool"),
            Column("is_assistance", "isAssistance", "bool"),
            Column("is_warp_safe", "isWarpSafe", "bool"),
            Column("disallow_auto_repeat", "disallowAutoRepeat", "bool"),
            Column("published", "published", "bool"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="dogmaAttributeCategories.jsonl",
        table="ref_dogma_attribute_categories",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="dogmaUnits.jsonl",
        table="ref_dogma_units",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="dbuffCollections.jsonl",
        table="ref_dbuff_collections",
        columns=[
            Column("id", "_key", "int"),
            _overflow(),
        ],
    ),

    # ============ INDUSTRY / BLUEPRINTS ====================================

    TableSpec(
        file="blueprints.jsonl",
        table="ref_blueprints",
        columns=[
            Column("id", "_key", "int"),
            Column("blueprint_type_id", "blueprintTypeID", "int"),
            Column("max_production_limit", "maxProductionLimit", "int"),
            _overflow(),
        ],
    ),

    # ============ FACTIONS / RACES / BLOODLINES ============================

    TableSpec(
        file="factions.jsonl",
        table="ref_factions",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("corporation_id", "corporationID", "int"),
            Column("militia_corporation_id", "militiaCorporationID", "int"),
            Column("solar_system_id", "solarSystemID", "int"),
            Column("icon_id", "iconID", "int"),
            Column("size_factor", "sizeFactor", "float"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="races.jsonl",
        table="ref_races",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("ship_type_id", "shipTypeID", "int"),
            Column("icon_id", "iconID", "int"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="bloodlines.jsonl",
        table="ref_bloodlines",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("race_id", "raceID", "int"),
            Column("corporation_id", "corporationID", "int"),
            Column("icon_id", "iconID", "int"),
            Column("charisma", "charisma", "int"),
            Column("intelligence", "intelligence", "int"),
            Column("memory", "memory", "int"),
            Column("perception", "perception", "int"),
            Column("willpower", "willpower", "int"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="ancestries.jsonl",
        table="ref_ancestries",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("bloodline_id", "bloodlineID", "int"),
            Column("icon_id", "iconID", "int"),
            Column("charisma", "charisma", "int"),
            Column("intelligence", "intelligence", "int"),
            Column("memory", "memory", "int"),
            Column("perception", "perception", "int"),
            Column("willpower", "willpower", "int"),
            _overflow(),
        ],
    ),

    # ============ NPC CORPS / STATIONS / CHARACTERS ========================

    TableSpec(
        file="npcCorporations.jsonl",
        table="ref_npc_corporations",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("ticker_name", "tickerName", "str"),
            Column("ceo_id", "ceoID", "int"),
            Column("station_id", "stationID", "int"),
            Column("size", "size", "str"),
            Column("extent", "extent", "str"),
            Column("tax_rate", "taxRate", "float"),
            Column("min_security", "minSecurity", "float"),
            Column("deleted", "deleted", "bool"),
            Column("has_player_personnel_manager", "hasPlayerPersonnelManager", "bool"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="npcCorporationDivisions.jsonl",
        table="ref_npc_corporation_divisions",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("internal_name", "internalName", "str"),
            Column("display_name", "displayName", "str"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="npcStations.jsonl",
        table="ref_npc_stations",
        columns=[
            Column("id", "_key", "int"),
            Column("solar_system_id", "solarSystemID", "int"),
            Column("owner_id", "ownerID", "int"),
            Column("operation_id", "operationID", "int"),
            Column("type_id", "typeID", "int"),
            Column("orbit_id", "orbitID", "int"),
            Column("reprocessing_efficiency", "reprocessingEfficiency", "float"),
            Column("reprocessing_stations_take", "reprocessingStationsTake", "float"),
            Column("reprocessing_hangar_flag", "reprocessingHangarFlag", "int"),
            Column("use_operation_name", "useOperationName", "bool"),
            Column("position_x", "position.x", "float"),
            Column("position_y", "position.y", "float"),
            Column("position_z", "position.z", "float"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="npcCharacters.jsonl",
        table="ref_npc_characters",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("corporation_id", "corporationID", "int"),
            Column("bloodline_id", "bloodlineID", "int"),
            Column("race_id", "raceID", "int"),
            Column("location_id", "locationID", "int"),
            Column("ceo", "ceo", "bool"),
            Column("gender", "gender", "bool"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="stationOperations.jsonl",
        table="ref_station_operations",
        columns=[
            Column("id", "_key", "int"),
            Column("operation_name", "operationName", "name"),
            Column("activity_id", "activityID", "int"),
            Column("border", "border", "float"),
            Column("corridor", "corridor", "float"),
            Column("fringe", "fringe", "float"),
            Column("hub", "hub", "float"),
            Column("manufacturing_factor", "manufacturingFactor", "float"),
            Column("research_factor", "researchFactor", "float"),
            Column("ratio", "ratio", "float"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="stationServices.jsonl",
        table="ref_station_services",
        columns=[
            Column("id", "_key", "int"),
            Column("service_name", "serviceName", "name"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="agentTypes.jsonl",
        table="ref_agent_types",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="agentsInSpace.jsonl",
        table="ref_agents_in_space",
        columns=[
            Column("id", "_key", "int"),
            Column("solar_system_id", "solarSystemID", "int"),
            Column("dungeon_id", "dungeonID", "int"),
            Column("spawn_point_id", "spawnPointID", "int"),
            Column("type_id", "typeID", "int"),
            _overflow(),
        ],
    ),

    # ============ SKILLS / CERTS / CHARACTER ===============================

    TableSpec(
        file="certificates.jsonl",
        table="ref_certificates",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("group_id", "groupID", "int"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="masteries.jsonl",
        table="ref_masteries",
        columns=[
            Column("id", "_key", "int"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="characterAttributes.jsonl",
        table="ref_character_attributes",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("icon_id", "iconID", "int"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="cloneGrades.jsonl",
        table="ref_clone_grades",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            _overflow(),
        ],
    ),

    # ============ VISUAL ASSETS ============================================

    TableSpec(
        file="icons.jsonl",
        table="ref_icons",
        columns=[
            Column("id", "_key", "int"),
            Column("icon_file", "iconFile", "str"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="graphics.jsonl",
        table="ref_graphics",
        columns=[
            Column("id", "_key", "int"),
            Column("graphic_file", "graphicFile", "str"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="skins.jsonl",
        table="ref_skins",
        columns=[
            Column("id", "_key", "int"),
            Column("internal_name", "internalName", "str"),
            Column("skin_material_id", "skinMaterialID", "int"),
            Column("visible_tranquility", "visibleTranquility", "bool"),
            Column("visible_serenity", "visibleSerenity", "bool"),
            Column("allow_ccp_devs", "allowCCPDevs", "bool"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="skinMaterials.jsonl",
        table="ref_skin_materials",
        columns=[
            Column("id", "_key", "int"),
            Column("display_name", "displayName", "name"),
            Column("material_set_id", "materialSetID", "int"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="skinLicenses.jsonl",
        table="ref_skin_licenses",
        columns=[
            Column("id", "_key", "int"),
            Column("license_type_id", "licenseTypeID", "int"),
            Column("skin_id", "skinID", "int"),
            Column("duration", "duration", "int"),
            _overflow(),
        ],
    ),

    # ============ PLANETARY / PI ==========================================

    TableSpec(
        file="planetResources.jsonl",
        table="ref_planet_resources",
        columns=[
            Column("id", "_key", "int"),
            Column("power", "power", "int"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="planetSchematics.jsonl",
        table="ref_planet_schematics",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("cycle_time", "cycleTime", "int"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="controlTowerResources.jsonl",
        table="ref_control_tower_resources",
        columns=[
            Column("id", "_key", "int"),
            _overflow(),
        ],
    ),

    # ============ MISC =====================================================

    TableSpec(
        file="translationLanguages.jsonl",
        table="ref_translation_languages",
        pk_kind="str",
        columns=[
            Column("id", "_key", "str"),
            Column("name", "name", "str"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="corporationActivities.jsonl",
        table="ref_corporation_activities",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="sovereigntyUpgrades.jsonl",
        table="ref_sovereignty_upgrades",
        columns=[
            Column("id", "_key", "int"),
            Column("mutually_exclusive_group", "mutually_exclusive_group", "str"),
            Column("power_allocation", "power_allocation", "int"),
            Column("workforce_allocation", "workforce_allocation", "int"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="mercenaryTacticalOperations.jsonl",
        table="ref_mercenary_tactical_operations",
        columns=[
            Column("id", "_key", "int"),
            Column("name", "name", "name"),
            Column("anarchy_impact", "anarchy_impact", "int"),
            Column("development_impact", "development_impact", "int"),
            Column("infomorph_bonus", "infomorph_bonus", "int"),
            _overflow(),
        ],
    ),

    TableSpec(
        file="freelanceJobSchemas.jsonl",
        table="ref_freelance_job_schemas",
        columns=[
            Column("id", "_key", "int"),
            _overflow(),
        ],
    ),
]


# Files intentionally skipped — they don't map to a single-keyed table
# ergonomically, or their content is already represented in another file.
# Documented here so an operator reading the directory listing knows why
# `_sde.jsonl` doesn't produce a row and doesn't think we forgot it.
SKIPPED_FILES = {
    "_sde.jsonl",  # Manifest — consumed separately for ref_snapshot / outbox.
}

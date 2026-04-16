"""OpenSearch index management and document mapping for killmails."""

from __future__ import annotations

from opensearchpy import OpenSearch

from killmail_search.config import Config
from killmail_search.log import get

log = get(__name__)

KILLMAIL_MAPPING = {
    "mappings": {
        "properties": {
            "killmail_id": {"type": "long"},
            "killed_at": {"type": "date"},
            "solar_system_id": {"type": "integer"},
            "constellation_id": {"type": "integer"},
            "region_id": {"type": "integer"},
            "region_name": {"type": "keyword"},
            "system_name": {"type": "keyword"},

            # Victim
            "victim_character_id": {"type": "long"},
            "victim_character_name": {"type": "text", "fields": {"keyword": {"type": "keyword"}}},
            "victim_corporation_id": {"type": "long"},
            "victim_corporation_name": {"type": "text", "fields": {"keyword": {"type": "keyword"}}},
            "victim_alliance_id": {"type": "long"},
            "victim_alliance_name": {"type": "text", "fields": {"keyword": {"type": "keyword"}}},
            "victim_ship_type_id": {"type": "integer"},
            "victim_ship_type_name": {"type": "text", "fields": {"keyword": {"type": "keyword"}}},
            "victim_ship_group_name": {"type": "keyword"},
            "victim_ship_category_name": {"type": "keyword"},
            "victim_damage_taken": {"type": "integer"},

            # Values
            "total_value": {"type": "double"},
            "hull_value": {"type": "double"},
            "fitted_value": {"type": "double"},
            "cargo_value": {"type": "double"},
            "drone_value": {"type": "double"},

            # Metadata
            "attacker_count": {"type": "integer"},
            "is_npc_kill": {"type": "boolean"},
            "is_solo_kill": {"type": "boolean"},

            # Attacker summary (top damage + final blow)
            "final_blow_character_name": {"type": "text", "fields": {"keyword": {"type": "keyword"}}},
            "final_blow_corporation_name": {"type": "keyword"},
            "final_blow_ship_type_name": {"type": "keyword"},

            # All involved — for "find kills where pilot X participated"
            "attacker_character_ids": {"type": "long"},
            "attacker_corporation_ids": {"type": "long"},
            "attacker_alliance_ids": {"type": "long"},
        }
    },
    "settings": {
        "number_of_shards": 2,
        "number_of_replicas": 0,
        "refresh_interval": "5s",
    },
}


def create_client(cfg: Config) -> OpenSearch:
    return OpenSearch(
        hosts=[cfg.opensearch_url],
        http_auth=(cfg.opensearch_username, cfg.opensearch_password),
        verify_certs=cfg.opensearch_verify_certs,
        ssl_show_warn=False,
    )


def ensure_index(client: OpenSearch, index_name: str) -> None:
    if not client.indices.exists(index=index_name):
        client.indices.create(index=index_name, body=KILLMAIL_MAPPING)
        log.info("created index", index=index_name)
    else:
        log.info("index exists", index=index_name)

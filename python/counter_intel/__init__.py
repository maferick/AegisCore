"""Counter-Intel Dossier worker package.

MVP design (locked):
  - Character-first; bloc is context not comparator.
  - Viewer-relative hostility resolved at render time — features stored
    here are tenant-agnostic.
  - Cold-start pilots tracked with has_sufficient_history = 0 rather
    than dropped; UI surfaces the gap explicitly.
  - No opaque embeddings — every feature is a plain scalar a human
    analyst can read straight off the dossier.

Pipeline (commit sequence):
  1. counter_intel.features  → ci_character_features_rolling (MariaDB)
  2. counter_intel.projection → Neo4j Character nodes + CO_OCCURS_WITH
  3. counter_intel.similarity → gds.knn + graph scores (Neo4j)
  4. counter_intel.anomalies  → ci_character_anomalies_rolling
  5. Laravel UI reads the MariaDB summary tables.
"""

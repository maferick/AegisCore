"""Battle-scoped role scoring (Spec 5, v0 uncalibrated).

Reads Spec 4 feature rows for one (battle_id, alliance_id,
partition_algo_version) tuple, applies the scoring coefficients of a
given weight_version, and writes:

  - decomposed score rows into battle_character_role_scores
    (structural / temporal / hull / final per character per role)
  - single-winner inference rows into battle_character_role_inference
    (one row per character; primary_role = highest-scoring role that
     clears its threshold + gap)

Scores are always persisted. Inference rows are persisted only when a
candidate clears its threshold + gap (FC, mainline_dps) or threshold
alone (logi, set-membership).

v0 coefficients are deliberately uncalibrated and conservative —
expected first-run behavior is few or zero inference rows. Calibration
is Spec 7's job; Spec 5 ships the scoring substrate and diagnostic
logging that Spec 7 keys off.

The scoring architecture is additively extensible: a future
`historical` score class slots in by registering a new compute
function and seeding coefficient rows under a new weight_version.
No schema change required; the CHECK on score_class already admits
'historical' after the Spec 5 schema prep migration.
"""

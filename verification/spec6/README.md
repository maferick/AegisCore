# Spec 6 verification

Structural checks + Mode A discipline reminders for the FC attestation
table and role-display pipeline.

## Files

| File                 | Purpose                                                 |
|----------------------|---------------------------------------------------------|
| `semantic_checks.sql`| DDL + FK + Mode A consumption shape queries             |

## How to run

```
docker compose --env-file .env -f infra/docker-compose.yml exec -T mariadb \
    mariadb -u root -p"$MARIADB_ROOT_PASSWORD" aegiscore < verification/spec6/semantic_checks.sql
```

## Manual checks — display surface

Load `/portal/battles/40605` as an authenticated user. Under the
sub-fleets section for alliance 99012122, sub-fleet 0 should show
12 logi pilots. FC = "uncertain" (Spec 5 produced no FC assignment).
Mainline anchor = "uncertain" on sub-fleet 0.

Load `/battles/40605` (public, unauth) — same display minus the
"Mark FC" control (Livewire component only renders under `@auth`).

Load `/portal/battles/40541` — sub-fleet 0 has 1 mainline_dps
assignment (matches Spec 5 output); FC = uncertain.

## Manual checks — attestation flow (donor-tier user)

1. Log into the portal as a user whose linked character has an
   active ad-free window (i.e. `User::isDonor()` returns true).
2. Navigate to any battle's sub-fleet with at least one member.
3. Click "Mark FC for this sub-fleet". A pilot picker opens.
4. Select a pilot who participated on the side; submit.
5. A green "Recorded. Thanks." flash appears inline, the picker
   collapses, and the sub-fleet card returns to its unchanged
   rendering. No row in the UI indicates an attestation was made.
6. Navigate to `/portal/my-fc-attestations` — the submission is
   listed with timestamp, battle link, sub-fleet, pilot, and note.

## Manual checks — negative paths

- Free-tier user: the "Mark FC" button does not render; direct
  Livewire `submit` call fails with "Donor tier required."
  (enforced in BattleFcAttestationRecorder::record).
- Attesting a character not in `battle_character_sub_fleet_membership`
  for the battle: recorder throws, flash displays the error message.
- Submitting with no pilot selected: recorder not called;
  "Pick a pilot first." flash shown.

## Mode A discipline reminder

The following surfaces MUST stay silent about attestations:

- public battle reports (`/battles/{id}`)
- portal battle reports (`/portal/battles/{id}`) — for users other
  than the submitter
- the portal dashboard
- any admin page listing battles or users

The **only** places an attestation surfaces:

- the submitter's own inline "Recorded" flash (transient, ≤ 4 seconds)
- the submitter's `/portal/my-fc-attestations` view

If a Spec 7 consumer wants attestation context outside these surfaces,
that's a Mode A breach decision and belongs to the Spec 7 design
document, not an incidental UI add.

## Spec 7 consumption shape

```sql
SELECT *
FROM (
    SELECT a.*,
           ROW_NUMBER() OVER (
               PARTITION BY battle_id, alliance_id, sub_fleet_id, partition_algo_version, user_id
               ORDER BY attested_at DESC, attestation_id DESC
           ) AS rn
    FROM battle_fc_user_attestations a
) t
WHERE rn = 1
  AND battle_id IN (...target battles...);
```

One row per (sub-fleet, user) tuple, always the latest. Prior
attestations are retained but the calibration-relevant label is
always the latest.

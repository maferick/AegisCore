# Spec 5 truth-set — 8 validation battles

Derived from manual inspection of the Spec 4 feature output +
killmail record on 2026-04-18. Conservative where the data allows
more than one reading; `"unknown"` where no single pilot clearly
fits the FC role from the killmail footprint.

Spec 5 verification should test:

- **FCs** — does the scorer rank the pilot(s) listed as FC highest-of-role
  within their sub-fleet? Ties between co-FCs are fine.
- **Logi** — does the scorer label every `character_id` in the logi
  list as `role=logi`? Precision and recall both matter.

Reading the lists:
- `b:40541` / `a:99011223` = battle_id / alliance_id.
- `sf:0` / `sf:1` = sub_fleet_id. Rows marked `sf:*` apply to every
  sub-fleet in the battle.
- `?` after a name = best guess; the killmail record doesn't prove it.

## 40365 Amamake — alliance 99011978

Small-gang pirate kitchen sink. Multiple wings, no obvious single FC.

- **FC**: `unknown` per sub-fleet. The one command-ship pilot
  (`634915984` BearThatCares, Pontifex, sub_fleet 0, degree 0.99) is
  the most plausible FC in sub-fleet 0. sub-fleet 1 and 2 are mixed
  frigs with no command hull; marked unknown.
  - sub_fleet 0: `634915984` BearThatCares (Pontifex) — likely FC
  - sub_fleet 1: unknown
  - sub_fleet 2: unknown
- **Logi**: none. No hull on this side classifies as `logi`.

## 40228 Aldranette — alliance 99014027

Brutix Navy DPS wing + Typhoon secondary wing.

- **FC**:
  - sub_fleet 0: `2124045888` zrxz-1 (Damnation, degree 0.88) — likely FC
  - sub_fleet 1: `2119972247` Honour antimuon (Eos, degree 0.64) — likely FC
- **Logi**: none.

## 40374 2E-ZR5 — alliance 99003581 (Fraternity)

Large Muninn line with Scimi logi, plus two small ad-hoc wings.

- **FC**:
  - sub_fleet 0: `2114375223` Richard Heraclid (Claymore, degree 0.97) — FC
    (co-FC candidates: `96335567` Neok Anderson, `2112414434` Reneil Askiras,
    both also Claymore, sub-fleet 0)
  - sub_fleet 1: `2115201818` Pax Laser (Stork, degree 0.90) — FC
  - sub_fleet 2: unknown (mixed EAF/Stabber/Tornado, no command hull)
  - sub_fleet 3: unknown (Arazu/Redeemer/Proteus, no command hull)
- **Logi** (4 Scimis):
  - `93295904` Finkat Erquilenne (Scimitar, sf 0)
  - `2120171838` jiaodeyuecanjiudeyuekuai (Scimitar, sf 0)
  - `1768488284` StupidFast (Scimitar, sf 0)
  - `2119205992` Xenon Inubara (Scimitar, sf 1)

## 40541 U-L4KS — alliance 99011223 (Sigma)

Drake Navy Issue gang with Monitor FC in one wing.

- **FC**:
  - sub_fleet 0: `519945752` AntientSphinx OR `1235035604` NashWolfe
    (both Claymore, degree 1.0, kpr 0.5). Either works as primary;
    score both as "FC-tier".
  - sub_fleet 1: `93444333` jacky Audeles (Monitor) — verified FC via
    Spec 4 review (`verification/spec4/monitor_audit_40541.md`).
    Note: Monitor has near-zero presence signal; Spec 5 must not
    rely on `presence_span` / `early_presence` / `late_presence` to
    detect him.
- **Logi**: none. (Small gang without remote reps.)

## 40478 Atioth — alliance 99003581 (Fraternity)

Thrasher + Catalyst gangs plus a pure Purifier bomber wing.

- **FC**:
  - sub_fleet 0: `2121995095` YesComreda (Bifrost, degree 0.80) — likely FC
  - sub_fleet 1: unknown (Catalyst gang, no command hull)
  - sub_fleet 2: unknown — pure bomber wing (10 Purifiers + 1 Helios),
    bombers typically have a wing lead off-grid but Spec 4 has no
    signal to identify them. Skip for role verification; match on
    `ship_class_category=bomber` instead.
- **Logi**: none.

## 40537 Komo — alliance 1900696668

Mixed fleet: Kikimora DPS wing + Raven blob. Several command destroyers;
unclear who's calling.

- **FC**:
  - sub_fleet 0: unclear — four command dessies in this sub-fleet.
    `722045149` Grace Kemp (Pontifex) has PageRank 0.92 but degree
    0.07 (anomalous split). `97110565` Kenzie Nardieu (Stork, degree
    0.55) is the top-degree command pilot. Mark as
    `"candidate": [722045149, 97110565]`.
  - sub_fleet 1: `2122948777` MMMMIlIIIlIllIlIIIlII lIIIllIIlIIlIII
    (Vulture, degree 0.95) — likely FC (alt-chain names suggest this
    is a boxer's command alt, still the only command hull in the wing).
- **Logi** (4 Deacons):
  - `2115670165` Kylon Muutaras (Deacon, sf 0)
  - `898899518` Orco Manic (Deacon, sf 0)
  - `96709117` Rin12 Saissore (Deacon, sf 0)
  - `96719428` Rock Maricadie (Deacon, sf 0)

## 40605 9S-GPT — alliance 99012122

Nightmare battleship fleet with Scimi logi wing + command ships.

- **FC**:
  - sub_fleet 0: `2113925609` serg koman (Claymore) OR `2115774750`
    BebolinA (Vulture). Both degree 1.0, kpr 0.4, presence_span 0.90.
    Score either/both as FC-tier.
  - sub_fleet 1: unknown (Nightmare-only, no command hull in wing).
- **Logi** (12 Scimis, all in sub_fleet 0):
  - `95386416` Antagonist Rin
  - `2122155936` Bewarriot Bre Maricadie
  - `2119769746` Blydnichka
  - `2117813099` CheshireMDA
  - `1274145929` Der Parol
  - `934780087` Gen Legion
  - `2114315970` Goah5 Makanen
  - `2119823766` JonHanter
  - `96956005` Lit Rayl
  - `2122550392` MayblePines
  - `94763527` Sasha Arlanow
  - `2119469881` Unio Valerio Borghese

## 40553 6RQ9-A — alliance 99011223 (Sigma)

Single cohesive DNI fleet, Monitor FC + Scimi logi. The cleanest
truth-set battle in the 8.

- **FC**:
  - sub_fleet 0 (the only sub-fleet): `93444333` jacky Audeles (Monitor).
    Co-FCs: `1235035604` NashWolfe, `2121203347` Xander Ryn (both
    Claymore, degree 1.0).
- **Logi** (13 Scimis, all in sub_fleet 0):
  - `93145041` BoerdeOrk
  - `94871329` Haakona Alexis
  - `2114609405` Hole Bitch
  - `2117730464` InGucci
  - `2120922492` Kju Xiwang
  - `2122795110` Lex Ashwari-Thrace
  - `775001006` mne lee
  - `127576638` Motty 007
  - `93663337` Nazumi T'vokna
  - `95116327` O'han Crendraven
  - `96764179` Pyrothes T'vokna
  - `732453653` rappter
  - `96914780` Tojo Crendraven

## Roll-up — counts

| battle | side          | sub_fleets | FCs named | FC unknown | logi count |
|--------|---------------|------------|-----------|------------|------------|
| 40365  | 99011978      | 3          | 1         | 2          | 0          |
| 40228  | 99014027      | 2          | 2         | 0          | 0          |
| 40374  | 99003581      | 4          | 2         | 2          | 4          |
| 40541  | 99011223      | 2          | 2         | 0          | 0          |
| 40478  | 99003581      | 3          | 1         | 2          | 0          |
| 40537  | 1900696668    | 2          | 2 (soft)  | 0          | 4          |
| 40605  | 99012122      | 2          | 1         | 1          | 12         |
| 40553  | 99011223      | 1          | 1         | 0          | 13         |

Total FC labels (strong + soft): **12**.
Total logi labels: **33**.

## Caveats for Spec 5 scoring

1. **Monitor-class FCs** (40541 sf1, 40553 sf0) will have
   `presence_span = 0`, `early_presence = 0`, `late_presence = 0`,
   and low `degree_centrality` relative to their sub-fleet. Any scorer
   that weights presence features > 0.2 will miss them. A
   hull-category prior (`ship_class_category = 'command'` ⇒ FC-prior
   bump) is the cheapest signal that recovers them.
2. **"Unknown" FC entries are not negatives.** They mean "cannot be
   verified from the killmail footprint alone." Spec 5 should not be
   graded down for producing FC predictions in those sub-fleets; it
   should only be graded when a prediction contradicts a named FC.
3. **Co-FC tolerance.** Where two or three pilots in the same
   sub-fleet all fly command hulls with identical degree/pagerank
   (e.g. 40374 sf0 Claymore trio), Spec 5 passes if it flags any one
   of them as FC. Flagging all of them is also correct.
4. **Bomber wings have no FC signal in v1** (40478 sf2). The wing
   lead typically flies a covert cyno or off-grid command ship.
   Don't expect Spec 4 features to recover him; exclude from the
   FC verification set.

-- WinterCo coalition labels — sourced from
-- https://wiki.winterco.org/en/guide/coalition/winter_coalition_member_and_allied_alliances
-- on 2026-04-19. Entity ids resolved via esi_entity_names + ESI
-- /universe/ids (for names not yet in our cache).
--
-- Unique key on coalition_entity_labels is
-- (entity_type, entity_id, raw_label, source), so ON DUPLICATE KEY
-- rows with source='wiki' upsert cleanly. Pre-existing bad rows with
-- source='seed' get corrected explicitly below.

-- Fix the two swapped legacy seed rows.
UPDATE coalition_entity_labels
   SET entity_name='Northern Coalition.', bloc_id=1, relationship_type_id=1,
       raw_label='wc.member', source='wiki', updated_at=NOW()
 WHERE id=20 AND entity_id=1727758877;

UPDATE coalition_entity_labels
   SET entity_name='Pandemic Legion', bloc_id=1, relationship_type_id=1,
       raw_label='wc.member', source='wiki', updated_at=NOW()
 WHERE id=22 AND entity_id=386292982;

-- Member alliances (relationship_type 1 = Member).
INSERT INTO coalition_entity_labels
  (entity_type, entity_id, entity_name, raw_label, bloc_id, relationship_type_id, source, is_active, created_at, updated_at)
VALUES
  ('alliance',   99001317, 'Banderlogs Alliance',         'wc.member', 1, 1, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99005393, 'Blades of Grass',             'wc.member', 1, 1, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99003581, 'Fraternity.',                 'wc.member', 1, 1, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99013537, 'Insidious.',                  'wc.member', 1, 1, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99009129, 'No Visual.',                  'wc.member', 1, 1, 'wiki', 1, NOW(), NOW()),
  ('alliance',  1727758877, 'Northern Coalition.',        'wc.member', 1, 1, 'wiki', 1, NOW(), NOW()),
  ('alliance',   386292982, 'Pandemic Legion',            'wc.member', 1, 1, 'wiki', 1, NOW(), NOW()),
  ('alliance',    99007203, 'Siberian Squads',            'wc.member', 1, 1, 'wiki', 1, NOW(), NOW()),
  ('alliance',  1042504553, 'Solyaris Chtonium',          'wc.member', 1, 1, 'wiki', 1, NOW(), NOW()),
  ('alliance',    99002685, 'Synergy of Steel',           'wc.member', 1, 1, 'wiki', 1, NOW(), NOW()),
  ('alliance',   498125261, 'Test Alliance Please Ignore','wc.member', 1, 1, 'wiki', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  entity_name=VALUES(entity_name),
  bloc_id=VALUES(bloc_id),
  relationship_type_id=VALUES(relationship_type_id),
  raw_label=VALUES(raw_label),
  source=VALUES(source),
  is_active=1,
  updated_at=NOW();

-- Associated (affiliate) alliances.
INSERT INTO coalition_entity_labels
  (entity_type, entity_id, entity_name, raw_label, bloc_id, relationship_type_id, source, is_active, created_at, updated_at)
VALUES
  ('alliance',   99005100, 'All My Friends Are Ded',                      'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99011206, 'APOC Fleet Auxiliary',                        'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99007681, 'Brack Regen',                                 'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99002542, 'Corelum Syndicate',                           'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99006494, 'Demonic Wheat Pineapple',                     'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99013541, 'Fraternity Auxiliary',                        'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99013941, 'James Webb Space Telescope',                  'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99002826, 'KIA Alliance',                                'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('alliance',  1411711376, 'Legion of xXDEATHXx',                        'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99005830, 'Memento Moriendo',                            'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99011521, 'No Concern',                                  'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99000163, 'Northern Associates.',                        'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99008783, 'Olde Guarde Historical Preservation Society', 'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99003500, 'Shadow of xXDEATHXx',                         'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99013160, 'Siberian Renters.',                           'wc.renter',    1, 5, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99006125, 'SLYCE Pirates',                               'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('alliance',   99014081, 'Synergy of Support',                          'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  entity_name=VALUES(entity_name),
  bloc_id=VALUES(bloc_id),
  relationship_type_id=VALUES(relationship_type_id),
  raw_label=VALUES(raw_label),
  source=VALUES(source),
  is_active=1,
  updated_at=NOW();

-- Wiki entries that resolved as standalone corporations (not in an
-- alliance). Label them at corp level so counter_intel's alliance-first
-- internal-subject query also picks up their members via
-- character_corporation_history.
INSERT INTO coalition_entity_labels
  (entity_type, entity_id, entity_name, raw_label, bloc_id, relationship_type_id, source, is_active, created_at, updated_at)
VALUES
  ('corporation',  98615098, 'AKINA mountain family',   'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('corporation',  98799725, 'Cobalt heart',            'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('corporation',  98591586, 'Manhattan Shipping LLC',  'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('corporation',  98547713, 'Rolling Squad',           'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('corporation',  98507124, 'S.F Express',             'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('corporation',  98690202, 'Soviet Arctic',           'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('corporation', 153638705, 'Apocalypse Now',          'wc.member',    1, 1, 'wiki', 1, NOW(), NOW()),
  ('corporation',  98815350, 'Heavy Assets Logistics',  'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW()),
  ('corporation',  98692527, 'Piggy Delivery',          'wc.affiliate', 1, 2, 'wiki', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  entity_name=VALUES(entity_name),
  bloc_id=VALUES(bloc_id),
  relationship_type_id=VALUES(relationship_type_id),
  raw_label=VALUES(raw_label),
  source=VALUES(source),
  is_active=1,
  updated_at=NOW();

-- Verify.
SELECT entity_type, bloc_id, COUNT(*) AS n
  FROM coalition_entity_labels
 WHERE is_active=1
 GROUP BY entity_type, bloc_id
 ORDER BY bloc_id, entity_type;
SELECT entity_type, entity_name, raw_label, bloc_id
  FROM coalition_entity_labels
 WHERE bloc_id=1 AND is_active=1
 ORDER BY entity_type, entity_name;

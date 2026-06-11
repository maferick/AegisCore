<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Spec 8 — role-tied auto-doctrine detection.
 *
 * Streams every loss killmail in window (~485k over 30d), resolves
 * each to (hull, role), and folds into a bounded in-memory cluster
 * state keyed by (hull_type_id, role_key, fingerprint). Fuzzy merge
 * + core-module extraction + UPSERT at the end.
 *
 * Role resolution per killmail:
 *   (a) Spec 5 role_inference if the pilot has one for this theater
 *   (b) ship_class_category hull fallback otherwise:
 *       logi → logi · bomber → bomber · command → command
 *       (Monitor hull → fc) · tackle → tackle · mainline → mainline_dps
 *       (uncategorized / 'other' → skip)
 *
 * Doctrine identity is GLOBAL: a fit flown by multiple corps is one
 * doctrine with many adopters. Per-corp adoption counts land in
 * auto_doctrine_adopters for Portal scoping.
 */
class ComputeAutoDoctrinesCommand extends Command
{
    protected $signature = 'battle:compute-auto-doctrines
                            {--window-days=30}
                            {--jaccard=0.65}
                            {--core-frequency=0.80}
                            {--strict-confidence=0.70}
                            {--batch-size=5000}
                            {--dry-run}';

    protected $description = 'Spec 8 — global role-tied doctrine clusters + per-corp adoption.';

    private const FLOOR_BY_ROLE = ['fc' => 2, 'command' => 2];
    private const FLOOR_CAPITAL = 5;
    private const FLOOR_DEFAULT = 10;
    private const CAPITAL_HULL_GROUPS = [485, 547, 659, 30, 1538, 4594];
    private const MONITOR_TYPE_ID = 45534;

    /** @var array<string, array> Cluster state across batches. Key = "$hull|$role|$fp". */
    private array $clusters = [];

    /** @var array<int, array{canonical:int,name:string}> type_id → stem+name. */
    private array $canonicalMap = [];

    public function handle(): int
    {
        $windowDays = (int) $this->option('window-days');
        $jaccard = (float) $this->option('jaccard');
        $coreFreq = (float) $this->option('core-frequency');
        $strictConf = (float) $this->option('strict-confidence');
        $batchSize = (int) $this->option('batch-size');
        $dryRun = (bool) $this->option('dry-run');

        $defaultWeight = DB::table('battle_role_weight_versions')->where('is_default', 1)->first();
        $wvId = $defaultWeight ? (int) $defaultWeight->weight_version : 0;
        if ($wvId > 0) {
            $this->info("Spec 5 inference source: weight_version={$wvId} ({$defaultWeight->label})");
        } else {
            $this->warn('No default weight_version — hull-fallback only.');
        }

        $since = now()->subDays($windowDays);
        $hullCatMap = DB::table('ship_class_category_mapping')->pluck('category', 'ship_type_id')->all();

        // Meta-variant canonical map: variation_parent_type_id points
        // every meta/T2/faction variant back to its T1 stem. Clustering
        // uses the stem as module identity so meta variants of the
        // same module collapse to one canonical slot. Populated once
        // per run; ~50k rows, ~5MB in memory.
        $this->canonicalMap = [];
        foreach (DB::table('ref_item_types')
            ->select('id', 'variation_parent_type_id', 'name')
            ->get() as $row
        ) {
            $id = (int) $row->id;
            $parent = $row->variation_parent_type_id !== null ? (int) $row->variation_parent_type_id : $id;
            $this->canonicalMap[$id] = [
                'canonical' => $parent,
                'name' => (string) $row->name,
            ];
        }
        $collapsed = 0;
        foreach ($this->canonicalMap as $id => $row) {
            if ($row['canonical'] !== $id) $collapsed++;
        }
        $this->info(sprintf('Canonical map loaded: %d rows (%d meta-variants collapse to stem)',
            count($this->canonicalMap), $collapsed));

        // Stream loss killmails in ascending killed_at batches using
        // a cursor-on-killmail_id pagination so big-window runs don't
        // load the whole 485k-row set at once.
        $lastId = 0;
        $totalRows = 0;
        $src = ['spec5' => 0, 'fallback' => 0, 'skipped' => 0, 'no_modules' => 0];

        while (true) {
            $rows = DB::select("
                SELECT k.killmail_id, k.killed_at, k.victim_character_id AS character_id,
                       k.victim_corporation_id AS corporation_id,
                       k.victim_ship_type_id AS hull_type_id,
                       k.victim_alliance_id AS alliance_id
                  FROM killmails k
                 WHERE k.killed_at >= ?
                   AND k.killmail_id > ?
                   AND k.victim_corporation_id > 0
                   AND k.victim_character_id IS NOT NULL
                   AND k.victim_ship_type_id IS NOT NULL
                 ORDER BY k.killmail_id ASC
                 LIMIT ?
            ", [$since, $lastId, $batchSize]);
            if ($rows === []) break;

            $lastId = (int) end($rows)->killmail_id;
            $totalRows += count($rows);

            $kmIds = array_map(fn ($r) => (int) $r->killmail_id, $rows);
            $charIds = array_values(array_unique(array_map(fn ($r) => (int) $r->character_id, $rows)));

            // Theater lookup for this batch.
            $theaterByKm = [];
            if ($kmIds !== []) {
                $ph = implode(',', array_fill(0, count($kmIds), '?'));
                foreach (DB::select(
                    "SELECT killmail_id, theater_id FROM battle_theater_killmails WHERE killmail_id IN ({$ph})",
                    $kmIds,
                ) as $m) {
                    $theaterByKm[(int) $m->killmail_id] = (int) $m->theater_id;
                }
            }

            // Inference lookup — limit to the chars in this batch.
            $inferenceMap = [];
            if ($wvId > 0 && $charIds !== []) {
                $ph = implode(',', array_fill(0, count($charIds), '?'));
                foreach (DB::select(
                    "SELECT battle_id, alliance_id, character_id, primary_role_key
                       FROM battle_character_role_inference
                      WHERE weight_version = ?
                        AND character_id IN ({$ph})",
                    array_merge([$wvId], $charIds),
                ) as $i) {
                    $inferenceMap["{$i->battle_id}|{$i->alliance_id}|{$i->character_id}"] = (string) $i->primary_role_key;
                }
            }

            // Module lookup for this batch.
            $modulesByKm = [];
            if ($kmIds !== []) {
                $ph = implode(',', array_fill(0, count($kmIds), '?'));
                foreach (DB::select(
                    "SELECT killmail_id, type_id, slot_category
                       FROM killmail_items
                      WHERE killmail_id IN ({$ph})
                        AND slot_category IN ('high','mid','low','rig','subsystem')
                        AND category_id IN (7, 32)",
                    $kmIds,
                ) as $it) {
                    $modulesByKm[(int) $it->killmail_id][] = [(int) $it->type_id, (string) $it->slot_category];
                }
            }

            // Fold each killmail into the cluster state.
            foreach ($rows as $r) {
                $kmid = (int) $r->killmail_id;
                $mods = $modulesByKm[$kmid] ?? [];
                if ($mods === []) { $src['no_modules']++; continue; }

                $role = null;
                $theater = $theaterByKm[$kmid] ?? 0;
                if ($theater > 0) {
                    $role = $inferenceMap["{$theater}|" . (int) $r->alliance_id . '|' . (int) $r->character_id] ?? null;
                }
                if ($role !== null) $src['spec5']++;
                else {
                    $role = self::hullFallbackRole((int) $r->hull_type_id, $hullCatMap);
                    if ($role !== null) $src['fallback']++;
                }
                if ($role === null) { $src['skipped']++; continue; }

                $this->foldKillIntoClusters($r, $role, $mods);
            }

            // Resolve alliance_id → bloc_id for everyone in this batch
            // once, so per-kill lookup stays O(1) on the next pass.
            // (Actual alliance + bloc adoption bucketing happens in
            // foldKillIntoClusters via the alliance_id on $r; bloc
            // resolution is done lazily at emit time.)

            $this->info(sprintf('Processed %d kms (last_id=%d; total %d)', count($rows), $lastId, $totalRows));
            unset($rows, $modulesByKm, $inferenceMap, $theaterByKm);
        }

        $this->info(sprintf('Role sources: spec5=%d fallback=%d skipped=%d no_modules=%d',
            $src['spec5'], $src['fallback'], $src['skipped'], $src['no_modules']));

        // Fuzzy merge per (hull, role). Group fingerprints by their
        // (hull, role) prefix so we don't compare across groups.
        $groups = [];
        foreach ($this->clusters as $compositeKey => $family) {
            [$hull, $role, $_fp] = explode('|', $compositeKey, 3);
            $groupKey = "{$hull}|{$role}";
            $groups[$groupKey][$compositeKey] = $family;
        }
        $merged = [];
        foreach ($groups as $gKey => $fams) {
            $after = self::fuzzyMerge($fams, $jaccard);
            $merged = array_merge($merged, $after);
        }
        $this->info(sprintf('Pre-merge clusters: %d · Post-merge: %d', count($this->clusters), count($merged)));

        if ($dryRun) {
            foreach ($merged as $key => $family) {
                $this->line(sprintf('  %s n=%d conf=%.2f adopters=%d',
                    $key, $family['observation_count'],
                    self::confidenceFn($family['observation_count']),
                    count($family['corp_counts']),
                ));
            }
            return self::SUCCESS;
        }

        $now = now();
        $emitted = 0; $active = 0; $skipped = 0;

        foreach ($merged as $family) {
            $core = $this->coreModules($family, $coreFreq);
            if ($core === []) { $skipped++; continue; }

            $canonical = self::canonicalFingerprint($core);
            $hullName = DB::table('ref_item_types')->where('id', $family['hull_type_id'])->value('name') ?? 'Unknown';
            $label = mb_substr("{$hullName} · " . self::roleLabel($family['role_key']), 0, 191);
            $confidence = self::confidenceFn($family['observation_count']);
            $floor = self::floorFor($family);
            $isActive = ($confidence >= $strictConf && $family['observation_count'] >= $floor) ? 1 : 0;

            DB::transaction(function () use ($family, $canonical, $label, $confidence, $isActive, $core, $now) {
                DB::table('auto_doctrines')->upsert(
                    [[
                        'hull_type_id' => $family['hull_type_id'],
                        'role_key' => $family['role_key'],
                        'canonical_fingerprint' => $canonical,
                        'canonical_name' => $label,
                        'observation_count' => $family['observation_count'],
                        'confidence' => round($confidence, 4),
                        'is_active' => $isActive,
                        'first_seen_at' => $family['first_seen'],
                        'last_seen_at' => $family['last_seen'],
                        'computed_at' => $now,
                        'updated_at' => $now,
                    ]],
                    ['hull_type_id', 'role_key', 'canonical_fingerprint'],
                    ['canonical_name', 'observation_count', 'confidence', 'is_active', 'last_seen_at', 'computed_at'],
                );
                $docId = (int) DB::table('auto_doctrines')
                    ->where('hull_type_id', $family['hull_type_id'])
                    ->where('role_key', $family['role_key'])
                    ->where('canonical_fingerprint', $canonical)
                    ->value('id');

                DB::table('auto_doctrine_modules')->where('doctrine_id', $docId)->delete();
                $modRows = [];
                foreach ($core as [$canonical_id, $slot, $q, $f, $mostCommon, $variants]) {
                    $modRows[] = [
                        'doctrine_id' => $docId,
                        'type_id' => $mostCommon,
                        'flag_category' => $slot,
                        'canonical_type_id' => $canonical_id,
                        'quantity' => $q,
                        'frequency' => $f,
                        'variants_json' => json_encode($variants),
                    ];
                }
                if ($modRows !== []) DB::table('auto_doctrine_modules')->insert($modRows);

                DB::table('auto_doctrine_adopters')->where('doctrine_id', $docId)->delete();
                DB::table('auto_doctrine_adopter_modules')->where('doctrine_id', $docId)->delete();
                $adopterRows = [];
                $adopterModuleRows = [];
                foreach ($family['corp_counts'] as $cid => $meta) {
                    $adopterRows[] = ['doctrine_id' => $docId, 'corporation_id' => $cid,
                        'observation_count' => $meta['n'],
                        'first_seen_at' => $meta['first'], 'last_seen_at' => $meta['last']];

                    // Corp-level core modules: lower cutoff than
                    // global (0.60 vs 0.80) so modules fielded in
                    // the majority of this corp's losses of the
                    // doctrine surface, even if only a subset.
                    // Needs min 3 observations to avoid noise —
                    // corps with 1-2 losses on a doctrine don't get
                    // adopter-module rows.
                    if ($meta['n'] < 3) continue;
                    foreach (($meta['module_counts'] ?? []) as $mk => $count) {
                        [$canonical, $slot] = explode('|', $mk);
                        $avg = $count / $meta['n'];
                        if (min(1.0, $avg) < 0.60) continue;
                        $adopterModuleRows[] = [
                            'doctrine_id' => $docId,
                            'corporation_id' => $cid,
                            'type_id' => (int) $canonical,
                            'canonical_type_id' => (int) $canonical,
                            'flag_category' => $slot,
                            'quantity' => max(1, (int) round($avg)),
                            'frequency' => round(min(1.0, $avg), 4),
                        ];
                    }
                }
                if ($adopterRows !== []) {
                    foreach (array_chunk($adopterRows, 500) as $chunk) {
                        DB::table('auto_doctrine_adopters')->insert($chunk);
                    }
                }
                if ($adopterModuleRows !== []) {
                    foreach (array_chunk($adopterModuleRows, 500) as $chunk) {
                        DB::table('auto_doctrine_adopter_modules')->insert($chunk);
                    }
                }

                // Alliance adopters
                DB::table('auto_doctrine_alliance_adopters')->where('doctrine_id', $docId)->delete();
                $allianceRows = [];
                foreach (($family['alliance_counts'] ?? []) as $aid => $meta) {
                    $allianceRows[] = ['doctrine_id' => $docId, 'alliance_id' => $aid,
                        'observation_count' => $meta['n'],
                        'first_seen_at' => $meta['first'], 'last_seen_at' => $meta['last']];
                }
                if ($allianceRows !== []) {
                    foreach (array_chunk($allianceRows, 500) as $chunk) {
                        DB::table('auto_doctrine_alliance_adopters')->insert($chunk);
                    }
                }

                // Bloc adopters: for each alliance, lookup its bloc
                // via coalition_entity_labels and aggregate.
                if (($family['alliance_counts'] ?? []) !== []) {
                    $blocMap = DB::table('coalition_entity_labels')
                        ->where('entity_type', 'alliance')
                        ->where('is_active', 1)
                        ->whereIn('entity_id', array_keys($family['alliance_counts']))
                        ->whereNotNull('bloc_id')
                        ->pluck('bloc_id', 'entity_id')
                        ->all();
                    $blocAgg = [];
                    foreach ($family['alliance_counts'] as $aid => $meta) {
                        $bid = $blocMap[$aid] ?? null;
                        if ($bid === null) continue;
                        if (! isset($blocAgg[$bid])) {
                            $blocAgg[$bid] = ['n' => 0, 'first' => $meta['first'], 'last' => $meta['last']];
                        }
                        $blocAgg[$bid]['n'] += $meta['n'];
                        if ($meta['first'] < $blocAgg[$bid]['first']) $blocAgg[$bid]['first'] = $meta['first'];
                        if ($meta['last']  > $blocAgg[$bid]['last'])  $blocAgg[$bid]['last']  = $meta['last'];
                    }
                    DB::table('auto_doctrine_bloc_adopters')->where('doctrine_id', $docId)->delete();
                    $blocRows = [];
                    foreach ($blocAgg as $bid => $meta) {
                        $blocRows[] = ['doctrine_id' => $docId, 'bloc_id' => $bid,
                            'observation_count' => $meta['n'],
                            'first_seen_at' => $meta['first'], 'last_seen_at' => $meta['last']];
                    }
                    if ($blocRows !== []) {
                        DB::table('auto_doctrine_bloc_adopters')->insert($blocRows);
                    }
                }

                // Pilot evidence list retained but capped per doctrine
                // to keep the table bounded.
                DB::table('auto_doctrine_pilots')->where('doctrine_id', $docId)->delete();
                $cap = 200;
                $pilotRows = array_slice($family['kills'] ?? [], 0, $cap);
                $out = [];
                foreach ($pilotRows as $k) {
                    $out[] = ['doctrine_id' => $docId, 'character_id' => $k['character_id'],
                        'battle_id' => 0, 'killmail_id' => $k['killmail_id'], 'seen_at' => $k['seen_at']];
                }
                if ($out !== []) DB::table('auto_doctrine_pilots')->insertOrIgnore($out);
            });

            $emitted++;
            if ($isActive) $active++;
        }

        $this->info("Clusters emitted: {$emitted} · active: {$active} · skipped (no core): {$skipped}");
        return self::SUCCESS;
    }

    private function foldKillIntoClusters(object $r, string $role, array $mods): void
    {
        // Canonicalize: variation_parent_type_id (if set) is the T1
        // stem; every meta/T2/faction variant maps to the same stem
        // for clustering identity. variant_counts tracks the specific
        // variants within each canonical+slot.
        $canonicalMods = [];
        $variantPairs = [];
        foreach ($mods as [$type_id, $slot]) {
            $canonical = $this->canonicalMap[$type_id]['canonical'] ?? $type_id;
            $canonicalMods[] = [$canonical, $slot];
            $variantPairs[] = [$canonical, $slot, $type_id];
        }
        $fp = self::fingerprint($canonicalMods);
        $key = $r->hull_type_id . '|' . $role . '|' . $fp;
        if (! isset($this->clusters[$key])) {
            $this->clusters[$key] = [
                'module_counts' => [], 'module_set' => [],
                'variant_counts' => [],
                'observation_count' => 0,
                'first_seen' => $r->killed_at, 'last_seen' => $r->killed_at,
                'kills' => [], 'corp_counts' => [], 'alliance_counts' => [],
                'hull_type_id' => (int) $r->hull_type_id,
                'role_key' => $role,
            ];
        }
        $f = &$this->clusters[$key];
        $f['observation_count']++;
        foreach ($canonicalMods as [$canonical, $slot]) {
            $k = "{$canonical}|{$slot}";
            $f['module_counts'][$k] = ($f['module_counts'][$k] ?? 0) + 1;
            $f['module_set'][$k] = true;
        }
        foreach ($variantPairs as [$canonical, $slot, $specific]) {
            $vk = "{$canonical}|{$slot}";
            if (! isset($f['variant_counts'][$vk])) $f['variant_counts'][$vk] = [];
            $f['variant_counts'][$vk][$specific] = ($f['variant_counts'][$vk][$specific] ?? 0) + 1;
        }
        if ($r->killed_at < $f['first_seen']) $f['first_seen'] = $r->killed_at;
        if ($r->killed_at > $f['last_seen'])  $f['last_seen']  = $r->killed_at;
        // Cap per-family kill evidence to 500 in-memory so big doctrines
        // don't balloon memory (full list would be redundant anyway).
        if (count($f['kills']) < 500) {
            $f['kills'][] = [
                'killmail_id' => (int) $r->killmail_id,
                'character_id' => (int) $r->character_id,
                'corporation_id' => (int) $r->corporation_id,
                'seen_at' => $r->killed_at,
            ];
        }
        $cid = (int) $r->corporation_id;
        if (! isset($f['corp_counts'][$cid])) {
            $f['corp_counts'][$cid] = ['n' => 0, 'first' => $r->killed_at, 'last' => $r->killed_at, 'module_counts' => []];
        }
        $f['corp_counts'][$cid]['n']++;
        if ($r->killed_at < $f['corp_counts'][$cid]['first']) $f['corp_counts'][$cid]['first'] = $r->killed_at;
        if ($r->killed_at > $f['corp_counts'][$cid]['last'])  $f['corp_counts'][$cid]['last']  = $r->killed_at;
        // Per-corp module tally uses canonical IDs so corp-variant
        // matching in the portal lines up with global canonical ids.
        foreach ($canonicalMods as [$canonical, $slot]) {
            $k = "{$canonical}|{$slot}";
            $f['corp_counts'][$cid]['module_counts'][$k] = ($f['corp_counts'][$cid]['module_counts'][$k] ?? 0) + 1;
        }

        // Alliance tally (zero alliance_id = no alliance; skip).
        $aid = (int) ($r->alliance_id ?? 0);
        if ($aid > 0) {
            if (! isset($f['alliance_counts'][$aid])) {
                $f['alliance_counts'][$aid] = ['n' => 0, 'first' => $r->killed_at, 'last' => $r->killed_at];
            }
            $f['alliance_counts'][$aid]['n']++;
            if ($r->killed_at < $f['alliance_counts'][$aid]['first']) $f['alliance_counts'][$aid]['first'] = $r->killed_at;
            if ($r->killed_at > $f['alliance_counts'][$aid]['last'])  $f['alliance_counts'][$aid]['last']  = $r->killed_at;
        }
        unset($f);
    }

    private static function hullFallbackRole(int $hullTypeId, array $hullCatMap): ?string
    {
        if ($hullTypeId === self::MONITOR_TYPE_ID) return 'fc';
        $cat = $hullCatMap[$hullTypeId] ?? null;
        return match ($cat) {
            'logi' => 'logi',
            'bomber' => 'bomber',
            'command' => 'command',
            'tackle' => 'tackle',
            'mainline' => 'mainline_dps',
            default => null,
        };
    }

    private static function fingerprint(array $modules): string
    {
        $c = $modules; sort($c); return md5(json_encode($c));
    }

    private static function fuzzyMerge(array $families, float $threshold): array
    {
        $keys = array_keys($families);
        $n = count($keys);
        if ($n < 2) return $families;
        $merged = true;
        while ($merged) {
            $merged = false;
            for ($i = 0; $i < count($keys); $i++) {
                for ($j = $i + 1; $j < count($keys); $j++) {
                    $a = $families[$keys[$i]]['module_set'] ?? [];
                    $b = $families[$keys[$j]]['module_set'] ?? [];
                    if (self::jaccard($a, $b) >= $threshold) {
                        $big = $families[$keys[$i]]['observation_count'] >= $families[$keys[$j]]['observation_count']
                            ? $keys[$i] : $keys[$j];
                        $small = $big === $keys[$i] ? $keys[$j] : $keys[$i];
                        $T = &$families[$big];
                        $S = $families[$small];
                        $T['observation_count'] += $S['observation_count'];
                        foreach ($S['module_counts'] as $k => $v) $T['module_counts'][$k] = ($T['module_counts'][$k] ?? 0) + $v;
                        $T['module_set'] = array_merge($T['module_set'], $S['module_set']);
                        if ($S['first_seen'] < $T['first_seen']) $T['first_seen'] = $S['first_seen'];
                        if ($S['last_seen']  > $T['last_seen'])  $T['last_seen']  = $S['last_seen'];
                        foreach ($S['kills'] as $k) {
                            if (count($T['kills']) >= 500) break;
                            $T['kills'][] = $k;
                        }
                        foreach ($S['corp_counts'] as $cid => $m) {
                            if (! isset($T['corp_counts'][$cid])) $T['corp_counts'][$cid] = $m;
                            else {
                                $T['corp_counts'][$cid]['n'] += $m['n'];
                                if ($m['first'] < $T['corp_counts'][$cid]['first']) $T['corp_counts'][$cid]['first'] = $m['first'];
                                if ($m['last']  > $T['corp_counts'][$cid]['last'])  $T['corp_counts'][$cid]['last']  = $m['last'];
                                foreach (($m['module_counts'] ?? []) as $mk => $mv) {
                                    $T['corp_counts'][$cid]['module_counts'][$mk] =
                                        ($T['corp_counts'][$cid]['module_counts'][$mk] ?? 0) + $mv;
                                }
                            }
                        }
                        foreach (($S['alliance_counts'] ?? []) as $aid => $m) {
                            if (! isset($T['alliance_counts'][$aid])) $T['alliance_counts'][$aid] = $m;
                            else {
                                $T['alliance_counts'][$aid]['n'] += $m['n'];
                                if ($m['first'] < $T['alliance_counts'][$aid]['first']) $T['alliance_counts'][$aid]['first'] = $m['first'];
                                if ($m['last']  > $T['alliance_counts'][$aid]['last'])  $T['alliance_counts'][$aid]['last']  = $m['last'];
                            }
                        }
                        unset($T, $families[$small]);
                        $keys = array_values(array_diff($keys, [$small]));
                        $merged = true;
                        break 2;
                    }
                }
            }
        }
        return $families;
    }

    private static function jaccard(array $a, array $b): float
    {
        if ($a === [] && $b === []) return 1.0;
        $union = count(array_unique(array_merge(array_keys($a), array_keys($b))));
        if ($union === 0) return 0.0;
        return count(array_intersect_key($a, $b)) / $union;
    }

    /**
     * @return list<array{0:int,1:string,2:int,3:float,4:int,5:array}>
     *   [canonical_type_id, slot, quantity, frequency,
     *    most_common_specific_type_id, variants_list]
     */
    private function coreModules(array $family, float $cutoff): array
    {
        $obs = max(1, $family['observation_count']);
        $out = [];
        foreach ($family['module_counts'] as $k => $count) {
            [$canonical, $slot] = explode('|', $k);
            $avg = $count / $obs;
            $freq = min(1.0, $avg);
            if ($freq < $cutoff) continue;
            $quantity = max(1, (int) round($avg));

            $variants = $family['variant_counts'][$k] ?? [(int) $canonical => $count];
            arsort($variants);
            $mostCommonSpecific = (int) array_key_first($variants);
            $variantList = [];
            foreach ($variants as $spec_id => $n) {
                $name = $this->canonicalMap[(int) $spec_id]['name'] ?? ('type ' . $spec_id);
                $variantList[] = [
                    'type_id' => (int) $spec_id,
                    'name' => $name,
                    'count' => (int) $n,
                    'frequency' => round($n / $obs, 4),
                ];
            }
            $out[] = [(int) $canonical, $slot, $quantity, round($freq, 4), $mostCommonSpecific, $variantList];
        }
        sort($out);
        return $out;
    }

    private static function canonicalFingerprint(array $core): string
    {
        $exp = [];
        foreach ($core as $row) {
            $tid = $row[0]; $slot = $row[1]; $q = $row[2];
            for ($i = 0; $i < $q; $i++) $exp[] = [$tid, $slot];
        }
        sort($exp);
        return md5(json_encode($exp));
    }

    private static function confidenceFn(int $n): float { return 1.0 - exp(-$n / 5.0); }

    private static function floorFor(array $family): int
    {
        $role = $family['role_key'];
        if (isset(self::FLOOR_BY_ROLE[$role])) return self::FLOOR_BY_ROLE[$role];
        $groupId = (int) (DB::table('ref_item_types')->where('id', $family['hull_type_id'])->value('group_id') ?? 0);
        if (in_array($groupId, self::CAPITAL_HULL_GROUPS, true)) return self::FLOOR_CAPITAL;
        return self::FLOOR_DEFAULT;
    }

    private static function roleLabel(string $role): string
    {
        return match ($role) {
            'fc' => 'FC', 'logi' => 'Logi', 'mainline_dps' => 'DPS',
            'tackle' => 'Tackle', 'bomber' => 'Bomber', 'command' => 'Command',
            default => ucfirst($role),
        };
    }
}

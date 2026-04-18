<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Spec 8 — role-tied auto-doctrine detection.
 *
 * Derives doctrines from the last N days of killmails where the
 * victim had both:
 *   - a Spec 5 role_inference row under the default weight_version
 *   - a non-zero alliance_id on the killmail
 *
 * Unlike SupplyCore's per-hull clusterer, this runs per
 * (alliance_id, hull_type_id, role_key) triple. Same hull + same
 * fit flying on a mainline_dps pilot is a different doctrine than
 * the identical fit seen on an "other" alt. This is the "less
 * overlap" requirement: the role axis separates clusters that
 * SupplyCore used to merge.
 *
 * Output: only doctrines where confidence >= STRICT_CONFIDENCE
 * (default 0.70) AND observation_count >= the tiered activation
 * floor are flagged is_active=1. Other rows persist for diagnostics
 * but the UI filters on is_active.
 */
class ComputeAutoDoctrinesCommand extends Command
{
    protected $signature = 'battle:compute-auto-doctrines
                            {--window-days=30 : Rolling window in days}
                            {--jaccard=0.65 : Jaccard threshold for fuzzy cluster merge}
                            {--core-frequency=0.80 : Module freq cutoff for "core" membership}
                            {--strict-confidence=0.70 : Min confidence to flag is_active}
                            {--dry-run : Print stats, no writes}';

    protected $description = 'Spec 8 — derive role-tied doctrines from the last N days of killmails.';

    // Tiered observation_count floors. A cluster below its floor stays
    // is_active=0 even if confidence passes. Command/FC ships fly in
    // tiny numbers so 2 is enough signal; capitals 5; subcaps 10 since
    // the role axis already filters noise.
    private const FLOOR_BY_ROLE = [
        'fc' => 2,
        'command' => 2,
    ];
    private const FLOOR_CAPITAL = 5;
    private const FLOOR_DEFAULT = 10;

    /** EVE group IDs for capitals (dread, carrier, super, titan, FAX). */
    private const CAPITAL_HULL_GROUPS = [485, 547, 659, 30, 1538];

    public function handle(): int
    {
        $windowDays = (int) $this->option('window-days');
        $jaccard = (float) $this->option('jaccard');
        $coreFreq = (float) $this->option('core-frequency');
        $strictConf = (float) $this->option('strict-confidence');
        $dryRun = (bool) $this->option('dry-run');

        $defaultWeight = DB::table('battle_role_weight_versions')->where('is_default', 1)->first();
        if ($defaultWeight === null) {
            $this->error('No default weight_version; promote one first.');
            return self::FAILURE;
        }
        $wvId = (int) $defaultWeight->weight_version;
        $this->info("Using weight_version={$wvId} ({$defaultWeight->label}).");

        $since = now()->subDays($windowDays);

        // 1. Collect (alliance, hull, role) candidate rows from
        //    killmails where the victim has both an alliance and a
        //    Spec 5 role inference.
        $rows = DB::select("
            SELECT k.killmail_id, k.killed_at, k.victim_character_id AS character_id,
                   k.victim_alliance_id AS alliance_id,
                   k.victim_ship_type_id AS hull_type_id,
                   i.primary_role_key AS role_key
              FROM killmails k
              JOIN battle_theater_killmails btk ON btk.killmail_id = k.killmail_id
              JOIN battle_character_role_inference i
                ON i.battle_id = btk.theater_id
               AND i.alliance_id = k.victim_alliance_id
               AND i.character_id = k.victim_character_id
               AND i.weight_version = ?
             WHERE k.killed_at >= ?
               AND k.victim_alliance_id > 0
               AND k.victim_character_id IS NOT NULL
               AND k.victim_ship_type_id IS NOT NULL
        ", [$wvId, $since]);

        $this->info('Candidate loss killmails: ' . count($rows));
        if ($rows === []) {
            return self::SUCCESS;
        }

        // 2. Pull fitted modules. Use slot_category enum; keep high/
        //    mid/low/rig/subsystem.
        $killmailIds = array_unique(array_map(fn ($r) => (int) $r->killmail_id, $rows));
        $items = DB::table('killmail_items')
            ->whereIn('killmail_id', $killmailIds)
            ->whereIn('slot_category', ['high', 'mid', 'low', 'rig', 'subsystem'])
            ->whereIn('category_id', [7, 32]) // Module, Subsystem
            ->select('killmail_id', 'type_id', 'slot_category')
            ->get();

        $modulesByKm = [];
        foreach ($items as $it) {
            $modulesByKm[(int) $it->killmail_id][] = [(int) $it->type_id, (string) $it->slot_category];
        }

        // 3. Group by (alliance, hull, role) + cluster by fingerprint.
        //    fingerprint = md5 of sorted multiset of (type_id, slot).
        $clusters = []; // $clusters[$key][$fp] = ['module_counts' => [...], 'kills' => [...]]
        foreach ($rows as $r) {
            $kmid = (int) $r->killmail_id;
            $mods = $modulesByKm[$kmid] ?? [];
            if ($mods === []) {
                continue;
            }
            $key = $r->alliance_id . '|' . $r->hull_type_id . '|' . $r->role_key;
            $fp = self::fingerprint($mods);
            $clusters[$key] ??= [];
            $clusters[$key][$fp] ??= [
                'module_counts' => [],
                'module_set' => [],
                'observation_count' => 0,
                'first_seen' => $r->killed_at,
                'last_seen' => $r->killed_at,
                'kills' => [],
                'alliance_id' => (int) $r->alliance_id,
                'hull_type_id' => (int) $r->hull_type_id,
                'role_key' => (string) $r->role_key,
            ];
            $family = &$clusters[$key][$fp];
            $family['observation_count']++;
            foreach ($mods as [$type_id, $slot]) {
                $k = "{$type_id}|{$slot}";
                $family['module_counts'][$k] = ($family['module_counts'][$k] ?? 0) + 1;
                $family['module_set'][$k] = true;
            }
            if ($r->killed_at < $family['first_seen']) $family['first_seen'] = $r->killed_at;
            if ($r->killed_at > $family['last_seen'])  $family['last_seen']  = $r->killed_at;
            $family['kills'][] = [
                'killmail_id' => $kmid,
                'character_id' => (int) $r->character_id,
                'battle_id' => $this->theaterForKill($kmid),
                'seen_at' => $r->killed_at,
            ];
            unset($family);
        }

        // 4. Fuzzy merge within each (alliance, hull, role) group.
        foreach ($clusters as $key => $families) {
            $clusters[$key] = self::fuzzyMerge($families, $jaccard);
        }

        // 5. Emit doctrines.
        $now = now();
        $emitted = 0; $skipped = 0; $active = 0;
        $allDoctrineIds = [];

        if ($dryRun) {
            foreach ($clusters as $key => $families) {
                foreach ($families as $fp => $family) {
                    $this->line(sprintf(
                        '  %s fp=%s… n=%d conf=%.2f',
                        $key, substr($fp, 0, 8), $family['observation_count'],
                        self::confidenceFn($family['observation_count']),
                    ));
                }
            }
            return self::SUCCESS;
        }

        DB::transaction(function () use (
            $clusters, $now, $coreFreq, $strictConf, &$emitted, &$skipped, &$active, &$allDoctrineIds
        ) {
            foreach ($clusters as $families) {
                foreach ($families as $family) {
                    $core = self::coreModules($family, $coreFreq);
                    if ($core === []) {
                        $skipped++;
                        continue;
                    }
                    $canonical = self::canonicalFingerprint($core);

                    $hullName = DB::table('ref_item_types')->where('id', $family['hull_type_id'])->value('name') ?? 'Unknown';
                    $allianceName = DB::table('esi_entity_names')
                        ->where('entity_id', $family['alliance_id'])
                        ->where('category', 'alliance')
                        ->value('name') ?? ('Alliance #' . $family['alliance_id']);
                    $roleLabel = self::roleLabel($family['role_key']);
                    $label = mb_substr("{$hullName} · {$roleLabel} · {$allianceName}", 0, 191);

                    $confidence = self::confidenceFn($family['observation_count']);
                    $floor = self::floorFor($family);
                    $isActive = ($confidence >= $strictConf && $family['observation_count'] >= $floor) ? 1 : 0;

                    DB::table('auto_doctrines')->upsert(
                        [[
                            'alliance_id' => $family['alliance_id'],
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
                        ['alliance_id', 'hull_type_id', 'role_key', 'canonical_fingerprint'],
                        ['canonical_name', 'observation_count', 'confidence', 'is_active', 'last_seen_at', 'computed_at'],
                    );
                    $docId = (int) DB::table('auto_doctrines')
                        ->where('alliance_id', $family['alliance_id'])
                        ->where('hull_type_id', $family['hull_type_id'])
                        ->where('role_key', $family['role_key'])
                        ->where('canonical_fingerprint', $canonical)
                        ->value('id');
                    $allDoctrineIds[] = $docId;

                    DB::table('auto_doctrine_modules')->where('doctrine_id', $docId)->delete();
                    $modRows = [];
                    foreach ($core as [$type_id, $slot, $quantity, $frequency]) {
                        $modRows[] = [
                            'doctrine_id' => $docId,
                            'type_id' => $type_id,
                            'flag_category' => $slot,
                            'quantity' => $quantity,
                            'frequency' => $frequency,
                        ];
                    }
                    if ($modRows !== []) {
                        DB::table('auto_doctrine_modules')->insert($modRows);
                    }

                    // Refresh pilot evidence list.
                    DB::table('auto_doctrine_pilots')->where('doctrine_id', $docId)->delete();
                    $pilotRows = [];
                    foreach ($family['kills'] as $k) {
                        $pilotRows[] = [
                            'doctrine_id' => $docId,
                            'character_id' => $k['character_id'],
                            'battle_id' => $k['battle_id'],
                            'killmail_id' => $k['killmail_id'],
                            'seen_at' => $k['seen_at'],
                        ];
                    }
                    if ($pilotRows !== []) {
                        // insertOrIgnore — the PK on (doctrine_id, killmail_id)
                        // blocks duplicates quietly.
                        DB::table('auto_doctrine_pilots')->insertOrIgnore($pilotRows);
                    }

                    $emitted++;
                    if ($isActive) $active++;
                }
            }
        });

        $this->info("Clusters processed: {$emitted}. Active (passed threshold + floor): {$active}. Skipped (no core modules): {$skipped}.");
        return self::SUCCESS;
    }

    /** md5 of sorted (type_id, slot) multiset. Preserves quantity. */
    private static function fingerprint(array $modules): string
    {
        $canon = $modules;
        sort($canon);
        return md5(json_encode($canon));
    }

    private static function fuzzyMerge(array $families, float $threshold): array
    {
        $fps = array_keys($families);
        $merged = true;
        while ($merged) {
            $merged = false;
            for ($i = 0; $i < count($fps); $i++) {
                for ($j = $i + 1; $j < count($fps); $j++) {
                    $a = $families[$fps[$i]]['module_set'] ?? [];
                    $b = $families[$fps[$j]]['module_set'] ?? [];
                    $jac = self::jaccard($a, $b);
                    if ($jac >= $threshold) {
                        // merge j into i
                        $big = $families[$fps[$i]]['observation_count'] >= $families[$fps[$j]]['observation_count']
                            ? $fps[$i] : $fps[$j];
                        $small = $big === $fps[$i] ? $fps[$j] : $fps[$i];
                        $T = &$families[$big];
                        $S = $families[$small];
                        $T['observation_count'] += $S['observation_count'];
                        foreach ($S['module_counts'] as $k => $v) {
                            $T['module_counts'][$k] = ($T['module_counts'][$k] ?? 0) + $v;
                        }
                        $T['module_set'] = array_merge($T['module_set'], $S['module_set']);
                        if ($S['first_seen'] < $T['first_seen']) $T['first_seen'] = $S['first_seen'];
                        if ($S['last_seen']  > $T['last_seen'])  $T['last_seen']  = $S['last_seen'];
                        $T['kills'] = array_merge($T['kills'], $S['kills']);
                        unset($T, $families[$small]);
                        $fps = array_values(array_diff($fps, [$small]));
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
        $aKeys = array_keys($a); $bKeys = array_keys($b);
        $union = count(array_unique(array_merge($aKeys, $bKeys)));
        if ($union === 0) return 0.0;
        $intersect = count(array_intersect_key($a, $b));
        return $intersect / $union;
    }

    private static function coreModules(array $family, float $cutoff): array
    {
        $obs = max(1, $family['observation_count']);
        $out = [];
        foreach ($family['module_counts'] as $k => $count) {
            [$type_id, $slot] = explode('|', $k);
            $avgPerKill = $count / $obs;
            $freq = min(1.0, $avgPerKill);
            if ($freq < $cutoff) continue;
            $quantity = max(1, (int) round($avgPerKill));
            $out[] = [(int) $type_id, $slot, $quantity, round($freq, 4)];
        }
        sort($out);
        return $out;
    }

    private static function canonicalFingerprint(array $core): string
    {
        $expanded = [];
        foreach ($core as [$type_id, $slot, $quantity, $_f]) {
            for ($i = 0; $i < $quantity; $i++) {
                $expanded[] = [$type_id, $slot];
            }
        }
        sort($expanded);
        return md5(json_encode($expanded));
    }

    private static function confidenceFn(int $n): float
    {
        return 1.0 - exp(-$n / 5.0);
    }

    private static function floorFor(array $family): int
    {
        $role = $family['role_key'];
        if (isset(self::FLOOR_BY_ROLE[$role])) {
            return self::FLOOR_BY_ROLE[$role];
        }
        $groupId = (int) (DB::table('ref_item_types')->where('id', $family['hull_type_id'])->value('group_id') ?? 0);
        if (in_array($groupId, self::CAPITAL_HULL_GROUPS, true)) {
            return self::FLOOR_CAPITAL;
        }
        return self::FLOOR_DEFAULT;
    }

    private static function roleLabel(string $role): string
    {
        return match ($role) {
            'fc' => 'FC',
            'logi' => 'Logi',
            'mainline_dps' => 'DPS',
            'tackle' => 'Tackle',
            'bomber' => 'Bomber',
            'command' => 'Command',
            default => ucfirst($role),
        };
    }

    private function theaterForKill(int $killmailId): int
    {
        static $cache = [];
        if (! isset($cache[$killmailId])) {
            $cache[$killmailId] = (int) (DB::table('battle_theater_killmails')
                ->where('killmail_id', $killmailId)
                ->value('theater_id') ?? 0);
        }
        return $cache[$killmailId];
    }
}

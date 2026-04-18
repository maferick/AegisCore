<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Populates `ship_class_category_mapping` from SDE `ref_item_types.group_id`
 * so ComputeAutoDoctrinesCommand's hull-fallback role resolver has coverage
 * for every ship that actually flies.
 *
 * Pre-seed the table held only 93 hulls — the doctrine clusterer skipped
 * 184k killmails because their hulls were unmapped (capitals, recons,
 * ewar frigs, strategic cruisers, marauders, etc.). Winterco ground-truth
 * coverage ran 79/137 "NO AUTO DOCTRINE FOR HULL"; adding these mappings
 * unlocks clustering for those hulls.
 */
class SeedHullCategoriesCommand extends Command
{
    protected $signature = 'doctrines:seed-hull-categories {--dry-run}';

    protected $description = 'Backfill ship_class_category_mapping from SDE ship groups.';

    /** Tactical function mapped into existing 5-role model. */
    private const GROUP_ROLE = [
        // DPS / mainline
        27   => 'mainline',  // Battleship
        26   => 'mainline',  // Cruiser
        324  => 'mainline',  // Assault Frigate
        419  => 'mainline',  // Combat Battlecruiser
        1201 => 'mainline',  // Attack Battlecruiser
        358  => 'mainline',  // Heavy Assault Cruiser
        963  => 'mainline',  // Strategic Cruiser
        1305 => 'mainline',  // Tactical Destroyer
        898  => 'mainline',  // Black Ops
        900  => 'mainline',  // Marauder
        906  => 'mainline',  // Combat Recon
        485  => 'mainline',  // Dreadnought
        547  => 'mainline',  // Carrier
        659  => 'mainline',  // Supercarrier
        30   => 'mainline',  // Titan
        4594 => 'mainline',  // Lancer Dreadnought

        // Tackle / ewar
        25   => 'tackle',    // Frigate
        831  => 'tackle',    // Interceptor
        420  => 'tackle',    // Destroyer
        893  => 'tackle',    // Electronic Attack Ship
        894  => 'tackle',    // Heavy Interdiction Cruiser
        541  => 'tackle',    // Interdictor
        1283 => 'tackle',    // Expedition Frigate
        833  => 'tackle',    // Force Recon

        // Logi
        832  => 'logi',      // Logistics
        1527 => 'logi',      // Logistics Frigate
        1538 => 'logi',      // Force Auxiliary

        // Bombers
        834  => 'bomber',    // Stealth Bomber

        // Command / boost
        1534 => 'command',   // Command Destroyer
        540  => 'command',   // Command Ship
        941  => 'command',   // Industrial Command Ship
        883  => 'command',   // Capital Industrial (Rorqual)
        4902 => 'command',   // Expedition Command (Monitor)
    ];

    /**
     * Ship-type overrides applied AFTER group-based seeding, informed by
     * Winter Coalition ground-truth fit catalog. CCP's group_id sometimes
     * buckets multi-role hulls into the wrong bucket for fleet tactical
     * purposes (T1 support frigs sit in the generic Frigate group next to
     * Rifters; T1 shield/armor logi cruisers sit in Cruiser next to DPS).
     *
     * Only unambiguous-role overrides are listed here — hulls whose WC
     * label signal is mixed (Loki, Vindicator, Scorpion, etc.) are left
     * to behavior scoring.
     */
    private const TYPE_OVERRIDES = [
        // T1 logi frigates — sit in Frigate group with tackle/DPS hulls.
        582  => 'logi',      // Bantam
        599  => 'logi',      // Burst
        590  => 'logi',      // Inquisitor
        592  => 'logi',      // Navitas
        // T1 shield logi cruisers — sit in Cruiser group with DPS hulls.
        620  => 'logi',      // Osprey
        631  => 'logi',      // Scythe
        // T1 armor logi cruiser.
        634  => 'logi',      // Exequror
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $existing = DB::table('ship_class_category_mapping')
            ->pluck('category', 'ship_type_id')
            ->all();
        $this->info(sprintf('Existing mapping: %d hulls', count($existing)));

        $groups = array_keys(self::GROUP_ROLE);
        $ships = DB::table('ref_item_types')
            ->whereIn('group_id', $groups)
            ->where('published', 1)
            ->select('id', 'name', 'group_id')
            ->get();
        $this->info(sprintf('Published ships in mapped groups: %d', $ships->count()));

        $now = now();
        $inserts = [];
        $reclass = [];
        foreach ($ships as $s) {
            $role = self::GROUP_ROLE[$s->group_id] ?? null;
            if ($role === null) continue;
            $current = $existing[$s->id] ?? null;
            if ($current === $role) continue;
            if ($current === null) {
                $inserts[] = [
                    'ship_type_id' => (int) $s->id,
                    'category' => $role,
                    'computed_at' => $now,
                ];
            } else {
                $reclass[] = [(int) $s->id, $s->name, $current, $role];
            }
        }

        $this->info(sprintf('New inserts: %d', count($inserts)));
        $this->info(sprintf('Skipped reclassifications (existing rows kept): %d', count($reclass)));
        if ($reclass !== []) {
            $this->table(
                ['ship_type_id', 'name', 'kept', 'sde-group-suggests'],
                array_slice($reclass, 0, 20),
            );
            if (count($reclass) > 20) $this->comment(sprintf('(+%d more)', count($reclass) - 20));
        }

        // WC ground-truth overrides — applied whether or not the row
        // already exists, since these explicitly reject the SDE group
        // default.
        $overrideChanges = [];
        foreach (self::TYPE_OVERRIDES as $typeId => $role) {
            $current = $existing[$typeId] ?? null;
            // Factor in rows we're about to insert this run.
            foreach ($inserts as $row) {
                if ((int) $row['ship_type_id'] === $typeId) {
                    $current = $row['category'];
                    break;
                }
            }
            if ($current === $role) continue;
            $overrideChanges[] = [$typeId, $current, $role];
        }
        if ($overrideChanges !== []) {
            $this->info(sprintf('WC ground-truth overrides: %d', count($overrideChanges)));
            $this->table(['type_id', 'from', 'to'], $overrideChanges);
        }

        if ($dryRun) {
            $this->comment('Dry run — no DB writes.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($inserts, $overrideChanges) {
            foreach (array_chunk($inserts, 500) as $chunk) {
                DB::table('ship_class_category_mapping')->insert($chunk);
            }
            foreach ($overrideChanges as [$typeId, $_from, $to]) {
                DB::table('ship_class_category_mapping')
                    ->where('ship_type_id', $typeId)
                    ->update(['category' => $to, 'computed_at' => now()]);
                // If row didn't exist yet, insert it.
                DB::table('ship_class_category_mapping')
                    ->insertOrIgnore(['ship_type_id' => $typeId, 'category' => $to, 'computed_at' => now()]);
            }
        });
        $this->info('Wrote mapping updates.');
        return self::SUCCESS;
    }
}

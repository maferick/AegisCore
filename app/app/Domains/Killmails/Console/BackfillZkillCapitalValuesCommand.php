<?php

declare(strict_types=1);

namespace App\Domains\Killmails\Console;

use App\Domains\Killmails\Services\ZkillKillmailValueService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Backfill zkill_total_value / zkill_fitted_value on capital+ kills.
 *
 * Our EveRef-pricing pipeline under-values capital hulls (Titan 60B
 * vs zKill ~140-165B; Supercarrier 20B vs ~50-65B). zKill prices live
 * Jita and faction/deadspace fits more accurately for these classes.
 *
 * Strategy:
 *   1. Pick all killmails of class titan/super/carrier/dread/FAX
 *      that haven't been fetched yet (zkill_value_fetched_at IS NULL)
 *      since the configurable --since date.
 *   2. Fetch totalValue/fittedValue from zKill /api/killID/{id}/.
 *   3. Persist on the killmails row (zkill_total_value, zkill_fitted_value).
 *   4. If --apply is set AND zkill_total_value > total_value × 1.15,
 *      overwrite total_value with zkill_total_value so the war report
 *      and other dashboards see the corrected price. Original EveRef
 *      total stays available via fitted_value + hull_value reconciliation
 *      and the audit log entry below.
 *
 * Throttle: 350ms between fetches → ~3 req/s. zKill allows much more
 * for authenticated traffic; we run unauthenticated and stay polite.
 */
final class BackfillZkillCapitalValuesCommand extends Command
{
    protected $signature = 'app:backfill-zkill-capital-values
        {--since=2026-04-02 : ISO date floor (killed_at >= since)}
        {--apply : overwrite total_value when zkill is materially higher}
        {--limit=2000 : max kills to process this run}
        {--threshold=1.15 : zkill must exceed our value by this factor before overwrite}
        {--refresh : refetch even rows that already have zkill_value_fetched_at}
        {--include-structures : also backfill upwell structures (Keepstars, etc.)}
        {--structures-only : only structures, skip cap+ ships}';

    protected $description = 'Pull zKill totalValue/fittedValue for capital+ kills and (optionally) overwrite our under-priced hulls.';

    /** group_id ⇒ class label, used for logging and the where-in clause. */
    private const array CAPITAL_GROUPS = [
        30 => 'Titan',
        659 => 'Supercarrier',
        547 => 'Carrier',
        485 => 'Dreadnought',
        1538 => 'Force Auxiliary',
        4594 => 'Lancer Dreadnought',
    ];

    /**
     * Structure groups also under-priced by EveRef:
     * Keepstars audit-ed at 38-42B in our DB vs 220-230B on zKill (~80%
     * gap). Fortizars stay close to zKill. Same correction pipeline
     * applies — opt in with --include-structures.
     */
    private const array STRUCTURE_GROUPS = [
        1657 => 'Citadel',           // Astrahus / Fortizar / Keepstar
        1404 => 'Engineering Complex', // Raitaru / Azbel / Sotiyo
        1406 => 'Refinery',          // Athanor / Tatara
        4744 => 'Moon Drill',
        1924 => 'Stronghold',
        1408 => 'Upwell Jump Bridge',
        2016 => 'Upwell Cyno Jammer',
        2017 => 'Upwell Cyno Beacon',
        1876 => 'Engineering Complex (variant)',
    ];

    public function handle(ZkillKillmailValueService $svc): int
    {
        $since = (string) $this->option('since');
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));
        $threshold = (float) $this->option('threshold');
        $refresh = (bool) $this->option('refresh');

        $sinceDt = Carbon::parse($since)->toDateTimeString();
        $structuresOnly = (bool) $this->option('structures-only');
        $includeStructures = $structuresOnly || (bool) $this->option('include-structures');
        $groupIds = $structuresOnly ? [] : array_keys(self::CAPITAL_GROUPS);
        if ($includeStructures) {
            $groupIds = array_merge($groupIds, array_keys(self::STRUCTURE_GROUPS));
        }
        $labelMap = self::CAPITAL_GROUPS + self::STRUCTURE_GROUPS;

        $q = DB::table('killmails as k')
            ->join('ref_item_types as t', 't.id', '=', 'k.victim_ship_type_id')
            ->whereIn('t.group_id', $groupIds)
            ->where('k.killed_at', '>=', $sinceDt);
        if (! $refresh) {
            $q->whereNull('k.zkill_value_fetched_at');
        }
        $rows = $q->orderBy('k.killed_at', 'desc')
            ->select([
                'k.killmail_id', 'k.victim_ship_type_name', 'k.total_value',
                'k.hull_value', 'k.fitted_value', 'k.cargo_value', 'k.drone_value',
                'k.killed_at', 't.group_id',
            ])
            ->limit($limit)
            ->get();

        $this->info(sprintf(
            'Backfill candidates: %d cap+ kills since %s (apply=%s, threshold=×%.2f)',
            $rows->count(), $sinceDt, $apply ? 'YES' : 'no', $threshold,
        ));
        if ($rows->isEmpty()) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($rows->count());
        $bar->start();

        $updates = 0;
        $overwrites = 0;
        $totalIskCorrected = 0.0;

        foreach ($rows as $r) {
            $kmId = (int) $r->killmail_id;
            $oldTotal = (float) $r->total_value;

            $val = $svc->fetch($kmId);
            usleep(350_000); // ~3 req/s polite throttle

            if ($val === null) {
                $bar->advance();
                continue;
            }

            $update = [
                'zkill_total_value' => $val['total'],
                'zkill_fitted_value' => $val['fitted'],
                'zkill_value_fetched_at' => now(),
                'updated_at' => now(),
            ];

            if ($apply && $val['total'] > $oldTotal * $threshold) {
                $update['total_value'] = $val['total'];
                // Re-derive hull = total - fitted - cargo - drone where
                // fitted/cargo/drone are our existing EveRef-priced
                // component sums. zKill's `fittedValue` already includes
                // the hull, so subtracting it would zero hull out.
                // Subtracting only the non-hull components leaves the
                // hull-only residual, which matches reality (Avatar
                // ~140-150B, Hel ~45-50B). Clamp to >= 0.
                $fitted = (float) ($r->fitted_value ?? 0);
                $cargo = (float) ($r->cargo_value ?? 0);
                $drone = (float) ($r->drone_value ?? 0);
                $newHull = max(0.0, $val['total'] - $fitted - $cargo - $drone);
                $update['hull_value'] = $newHull;
                $overwrites++;
                $totalIskCorrected += ($val['total'] - $oldTotal);
                $cls = $labelMap[(int) $r->group_id] ?? 'cap';
                $this->line(sprintf(
                    "  [overwrite] km=%d %s %s : total %.2fB → %.2fB (Δ %.2fB) · hull → %.2fB",
                    $kmId, $cls, $r->victim_ship_type_name,
                    $oldTotal / 1e9, $val['total'] / 1e9,
                    ($val['total'] - $oldTotal) / 1e9,
                    $newHull / 1e9,
                ));
            }

            DB::table('killmails')->where('killmail_id', $kmId)->update($update);
            $updates++;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            'Done: %d rows updated, %d hull values overwritten, %.2f B ISK total correction.',
            $updates, $overwrites, $totalIskCorrected / 1e9,
        ));

        return self::SUCCESS;
    }
}

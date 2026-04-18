<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Spec 7 job C — operator-approved promotion of a weight_version to
 * default + per-role flip of inference ui_state 'beta' → 'production'.
 *
 * Gate: every role flipped to production must have a most-recent
 * battle_role_calibration_runs row with passed=1 under this
 * weight_version. Roles without a passing run stay beta.
 *
 * Effects:
 *   - clears is_default on every other weight_version row (the
 *     virtual is_default_key column enforces uniqueness via unique
 *     index but only when is_default=1; explicit clear is defensive)
 *   - sets is_default=1 on the target
 *   - updates battle_character_role_inference.ui_state to 'production'
 *     for every row matching (weight_version, primary_role_key IN passing_roles)
 *
 * Not idempotent in the literal sense — re-running flips the same
 * rows again harmlessly, but ui_state may already be 'production'.
 */
class PromoteWeightVersionCommand extends Command
{
    protected $signature = 'battle:promote-weight-version
                            {weight_version : Weight version id to promote}
                            {--roles=fc,logi,mainline_dps : Comma-separated role keys to flip to production}
                            {--force : Promote even if no passing calibration run exists for a role}';

    protected $description = 'Spec 7 job C — set is_default + flip ui_state on eligible roles after calibration approval.';

    public function handle(): int
    {
        $wv = (int) $this->argument('weight_version');
        $version = DB::table('battle_role_weight_versions')->where('weight_version', $wv)->first();
        if ($version === null) {
            $this->error("weight_version {$wv} not found.");
            return self::FAILURE;
        }
        $force = (bool) $this->option('force');
        $roles = array_filter(array_map('trim', explode(',', (string) $this->option('roles'))));

        $eligible = [];
        $blocked = [];
        foreach ($roles as $role) {
            $latest = DB::table('battle_role_calibration_runs')
                ->where('weight_version', $wv)
                ->where('role_key', $role)
                ->orderByDesc('evaluated_at')
                ->first();
            if ($latest === null) {
                $force ? $eligible[] = $role : $blocked[] = "{$role} (no calibration run)";
                continue;
            }
            if ((int) $latest->passed === 1 || $force) {
                $eligible[] = $role;
            } else {
                $blocked[] = sprintf('%s (accuracy %.4f < threshold %.4f)',
                    $role, (float) $latest->accuracy, (float) $latest->threshold);
            }
        }

        if ($blocked !== []) {
            $this->warn('Roles blocked from promotion:');
            foreach ($blocked as $b) $this->warn("  - {$b}");
            if (! $force) {
                $this->info('Pass --force to override (requires operator judgement).');
            }
        }
        if ($eligible === []) {
            $this->error('No eligible roles — nothing to do.');
            return self::FAILURE;
        }

        $this->info('Promoting roles to production: ' . implode(', ', $eligible));

        DB::transaction(function () use ($wv, $eligible): void {
            DB::table('battle_role_weight_versions')->where('weight_version', '!=', $wv)->update(['is_default' => 0]);
            DB::table('battle_role_weight_versions')->where('weight_version', $wv)->update(['is_default' => 1]);

            DB::table('battle_character_role_inference')
                ->where('weight_version', $wv)
                ->whereIn('primary_role_key', $eligible)
                ->update(['ui_state' => 'production']);
        });

        $this->info("weight_version {$wv} ({$version->label}) is now default. UI state flipped to production for " . count($eligible) . ' role(s).');

        return self::SUCCESS;
    }
}

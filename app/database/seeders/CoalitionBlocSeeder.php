<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\UsersCharacters\Models\CoalitionBloc;
use Illuminate\Database\Seeder;

/**
 * Seeds the baseline set of coalition blocs the classification system
 * needs before the resolver can produce anything useful.
 *
 * Idempotent: `updateOrCreate` on the unique `bloc_code` column. Safe
 * to re-run after deploys; adding a new bloc here is the supported way
 * to extend the registry without a schema migration.
 *
 * Invoked from {@see DatabaseSeeder::run()} and directly runnable via
 * `php artisan db:seed --class=CoalitionBlocSeeder`.
 *
 * `independent` and `unknown` are deliberate sentinel rows. The resolver
 * treats `independent` as "explicitly outside any mapped coalition" (no
 * inheritance, no consensus propagation) and `unknown` as "we have no
 * data" (fall through to fallback alignment). Distinguishing the two is
 * load-bearing for downstream trust signals.
 */
class CoalitionBlocSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'bloc_code' => CoalitionBloc::CODE_WINTERCO,
                'display_name' => 'WinterCo',
                'default_role' => CoalitionBloc::ROLE_COMBAT,
                'is_active' => true,
            ],
            [
                'bloc_code' => CoalitionBloc::CODE_B2,
                'display_name' => 'B2',
                'default_role' => CoalitionBloc::ROLE_COMBAT,
                'is_active' => true,
            ],
            [
                'bloc_code' => CoalitionBloc::CODE_CFC,
                'display_name' => 'CFC / Imperium',
                'default_role' => CoalitionBloc::ROLE_COMBAT,
                'is_active' => true,
            ],
            [
                'bloc_code' => CoalitionBloc::CODE_PANFAM,
                'display_name' => 'PanFam',
                'default_role' => CoalitionBloc::ROLE_COMBAT,
                'is_active' => true,
            ],
            [
                'bloc_code' => CoalitionBloc::CODE_INDEPENDENT,
                'display_name' => 'Independent',
                'default_role' => CoalitionBloc::ROLE_COMBAT,
                'is_active' => true,
            ],
            [
                'bloc_code' => CoalitionBloc::CODE_UNKNOWN,
                'display_name' => 'Unknown',
                'default_role' => CoalitionBloc::ROLE_COMBAT,
                'is_active' => true,
            ],
        ];

        foreach ($rows as $row) {
            CoalitionBloc::updateOrCreate(
                ['bloc_code' => $row['bloc_code']],
                $row,
            );
        }
    }
}

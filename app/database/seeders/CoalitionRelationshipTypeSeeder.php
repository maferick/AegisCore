<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\UsersCharacters\Models\CoalitionRelationshipType;
use Illuminate\Database\Seeder;

/**
 * Seeds the baseline set of coalition relationship types. Paired with
 * {@see CoalitionBlocSeeder} — the two together form the minimum
 * taxonomy the resolver needs before it can decompose any raw label
 * like `wc.member` into (bloc, relationship).
 *
 * Idempotent: `updateOrCreate` on `relationship_code`. Safe to re-run
 * and the supported way to extend the registry.
 *
 * `display_order` drives the "which relationship wins" tie-breaking
 * when an entity carries multiple labels — lower = more significant.
 * The resolver uses this to render and prioritise membership first
 * (`member` = 10) over peripheral roles (`renter` = 80).
 *
 * `inherits_alignment` controls whether classification flows from the
 * bloc down to the entity. `renter` is deliberately false: a renter
 * corp's diplomatic alignment is not implied by the rental contract.
 * All others inherit.
 */
class CoalitionRelationshipTypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'relationship_code' => CoalitionRelationshipType::CODE_MEMBER,
                'display_name' => 'Member',
                'default_role' => 'combat',
                'inherits_alignment' => true,
                'display_order' => 10,
            ],
            [
                'relationship_code' => CoalitionRelationshipType::CODE_AFFILIATE,
                'display_name' => 'Affiliate',
                'default_role' => 'combat',
                'inherits_alignment' => true,
                'display_order' => 20,
            ],
            [
                'relationship_code' => CoalitionRelationshipType::CODE_ALLIED,
                'display_name' => 'Allied',
                'default_role' => 'combat',
                'inherits_alignment' => true,
                'display_order' => 30,
            ],
            [
                'relationship_code' => CoalitionRelationshipType::CODE_LOGISTICS,
                'display_name' => 'Logistics',
                'default_role' => 'logistics',
                'inherits_alignment' => true,
                'display_order' => 40,
            ],
            [
                'relationship_code' => CoalitionRelationshipType::CODE_RENTER,
                'display_name' => 'Renter',
                'default_role' => 'renter',
                'inherits_alignment' => false,
                'display_order' => 80,
            ],
        ];

        foreach ($rows as $row) {
            CoalitionRelationshipType::updateOrCreate(
                ['relationship_code' => $row['relationship_code']],
                $row,
            );
        }
    }
}

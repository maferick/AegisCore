<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\UsersCharacters\Models\CoalitionBloc;
use App\Domains\UsersCharacters\Models\CoalitionEntityLabel;
use App\Domains\UsersCharacters\Models\CoalitionRelationshipType;
use Illuminate\Database\Seeder;

/**
 * Seeds a baseline set of coalition entity labels — the alliance-to-bloc
 * mappings that drive viewer-bloc inference on /account/settings.
 *
 * Idempotent: `updateOrCreate` on the uniqueness key
 * (entity_type, entity_id, raw_label, source). Safe to re-run. Admins
 * can add, edit, or remove labels at /admin/coalition-entity-labels; rows
 * added here carry `source = seed` so they're visually distinguishable
 * from manual admin tags.
 *
 * This is a starter set of well-known alliances. The full universe of
 * corps and alliances should be tagged through the admin UI or a future
 * bulk-import flow.
 *
 * Alliance IDs sourced from public EVE reference data (evewho.com).
 */
class CoalitionEntityLabelSeeder extends Seeder
{
    public function run(): void
    {
        // Pre-load bloc + relationship IDs so rows are FK-safe.
        $blocs = CoalitionBloc::where('is_active', true)
            ->pluck('id', 'bloc_code');

        $relationships = CoalitionRelationshipType::pluck('id', 'relationship_code');

        $member = $relationships[CoalitionRelationshipType::CODE_MEMBER];

        // ---------------------------------------------------------------
        // WinterCo (wc)
        // ---------------------------------------------------------------
        $wc = $blocs[CoalitionBloc::CODE_WINTERCO];

        // Alliance IDs verified against esi_entity_names cache /
        // zkillboard. Historical typos (99005393 labelled "Fraternity."
        // was actually "Blades of Grass", which is itself a WinterCo
        // alliance — keep the mapping, fix the display name) preserved
        // as WinterCo members. Real Fraternity. is 99003581, added
        // below as a separate row.
        $wintercoAlliances = [
            99003581 => 'Fraternity.',                 // real Fraternity.
            99005393 => 'Blades of Grass',             // previously mislabelled "Fraternity."
            99010562 => 'Winter Coalition',
            99011406 => 'Dracarys.',
            99012009 => 'Literally Triggered',
            99009310 => 'Siege Green.',
            99003838 => 'Ranger Regiment',
            99011223 => 'Mistakes Were Made.',
            99011834 => 'Valkyrie Alliance',
        ];

        // ---------------------------------------------------------------
        // B2 (b2)
        // ---------------------------------------------------------------
        $b2 = $blocs[CoalitionBloc::CODE_B2];

        $b2Alliances = [
            99012351 => 'B2 Coalition',
            99003214 => 'Brave Collective',
            99010079 => 'Eternal Requiem',
            99001954 => 'Severance',
            99011978 => 'Already Replaced.',
            99012166 => 'Stellae Renascitur',
        ];

        // ---------------------------------------------------------------
        // CFC / Imperium (cfc)
        // ---------------------------------------------------------------
        $cfc = $blocs[CoalitionBloc::CODE_CFC];

        // CCP alliance IDs — verified against zkillboard. Previously
        // had The Initiative. at 99005338; the real CCP id is
        // 1900696668 (observed in battle 4-HWWF, esi_entity_names
        // cache). "Get Off My Lawn" / "Bastion, The" sharing
        // entity_id 99003995 was an old typo collision that silently
        // clobbered one entry via PHP array dedup — corrected here.
        $cfcAlliances = [
            1354830081 => 'Goonswarm Federation',
            1900696668 => 'The Initiative.',
            99009163   => 'Tactical Narcotics Team',
            99003995   => 'Bastion, The',
            99009926   => 'Dracarys',
            1727758877 => 'Tactical Voltage',
        ];

        // ---------------------------------------------------------------
        // PanFam (panfam)
        // ---------------------------------------------------------------
        $panfam = $blocs[CoalitionBloc::CODE_PANFAM];

        $panfamAlliances = [
            99010079 => 'Pandemic Horde',
            386292982 => 'Northern Coalition.',
            99005805 => 'Pandemic Legion',
            99009163 => 'Horde Vanguard.',
            99011932 => 'Horde Avalanche.',
        ];

        // ---------------------------------------------------------------
        // Seed all labels
        // ---------------------------------------------------------------
        $allSets = [
            $wc      => $wintercoAlliances,
            $b2      => $b2Alliances,
            $cfc     => $cfcAlliances,
            $panfam  => $panfamAlliances,
        ];

        foreach ($allSets as $blocId => $alliances) {
            $blocCode = $blocs->search($blocId);

            foreach ($alliances as $allianceId => $name) {
                $rawLabel = "{$blocCode}.member";

                CoalitionEntityLabel::updateOrCreate(
                    [
                        'entity_type' => CoalitionEntityLabel::ENTITY_ALLIANCE,
                        'entity_id'   => $allianceId,
                        'raw_label'   => $rawLabel,
                        'source'      => CoalitionEntityLabel::SOURCE_SEED,
                    ],
                    [
                        'entity_name'          => $name,
                        'bloc_id'              => $blocId,
                        'relationship_type_id' => $member,
                        'is_active'            => true,
                    ],
                );
            }
        }
    }
}

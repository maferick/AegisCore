<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\UsersCharacters;

use App\Domains\UsersCharacters\Models\Character;
use App\Domains\UsersCharacters\Models\CharacterStanding;
use App\Domains\UsersCharacters\Models\CoalitionBloc;
use App\Domains\UsersCharacters\Models\CoalitionEntityLabel;
use App\Domains\UsersCharacters\Models\CoalitionRelationshipType;
use App\Domains\UsersCharacters\Models\CorporationAffiliationProfile;
use App\Domains\UsersCharacters\Models\EntityClassificationOverride;
use App\Domains\UsersCharacters\Models\ViewerContext;
use App\Domains\UsersCharacters\Models\ViewerEntityClassification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * Verifies that the {@see \App\Domains\UsersCharacters\Observers\ClassificationDirtyObserver}
 * correctly flips `is_dirty=1` on affected viewer_entity_classifications
 * rows when each of the four upstream input models is saved.
 */
final class ClassificationDirtyObserverTest extends TestCase
{
    use DatabaseMigrations;

    public function test_standing_save_marks_matching_target_dirty(): void
    {
        [$viewerContext, $classification] = $this->makeClassification(
            ViewerEntityClassification::ENTITY_ALLIANCE,
            99_000_001,
        );

        self::assertFalse($classification->is_dirty);

        CharacterStanding::query()->create([
            'owner_type' => CharacterStanding::OWNER_CORPORATION,
            'owner_id' => $viewerContext->viewer_corporation_id,
            'contact_id' => 99_000_001,
            'contact_type' => CharacterStanding::CONTACT_ALLIANCE,
            'standing' => '5.0',
            'source_character_id' => $viewerContext->character_id,
            'synced_at' => CarbonImmutable::now(),
        ]);

        self::assertTrue($classification->refresh()->is_dirty);
    }

    public function test_label_save_marks_matching_target_dirty(): void
    {
        [$viewerContext, $classification] = $this->makeClassification(
            ViewerEntityClassification::ENTITY_ALLIANCE,
            99_000_002,
        );

        $bloc = CoalitionBloc::query()->create([
            'bloc_code' => 'test-bloc',
            'display_name' => 'Test',
            'is_active' => true,
        ]);

        CoalitionEntityLabel::query()->create([
            'entity_type' => CoalitionEntityLabel::ENTITY_ALLIANCE,
            'entity_id' => 99_000_002,
            'raw_label' => 'test.member',
            'bloc_id' => $bloc->id,
            'source' => CoalitionEntityLabel::SOURCE_MANUAL,
            'is_active' => true,
        ]);

        self::assertTrue($classification->refresh()->is_dirty);
    }

    public function test_global_override_save_marks_all_viewers_dirty_for_target(): void
    {
        [$viewerContext, $classification] = $this->makeClassification(
            ViewerEntityClassification::ENTITY_CORPORATION,
            98_000_050,
        );

        EntityClassificationOverride::query()->create([
            'scope_type' => EntityClassificationOverride::SCOPE_GLOBAL,
            'viewer_context_id' => null,
            'target_entity_type' => ViewerEntityClassification::ENTITY_CORPORATION,
            'target_entity_id' => 98_000_050,
            'forced_alignment' => ViewerEntityClassification::ALIGNMENT_HOSTILE,
            'reason' => 'Test global override.',
            'is_active' => true,
        ]);

        self::assertTrue($classification->refresh()->is_dirty);
    }

    public function test_affiliation_profile_save_marks_corp_target_dirty(): void
    {
        [$viewerContext, $classification] = $this->makeClassification(
            ViewerEntityClassification::ENTITY_CORPORATION,
            98_000_099,
        );

        CorporationAffiliationProfile::query()->create([
            'corporation_id' => 98_000_099,
            'current_alliance_id' => 99_000_099,
            'observed_at' => CarbonImmutable::now(),
        ]);

        self::assertTrue($classification->refresh()->is_dirty);
    }

    public function test_standing_for_character_contact_type_does_not_mark_dirty(): void
    {
        [$viewerContext, $classification] = $this->makeClassification(
            ViewerEntityClassification::ENTITY_ALLIANCE,
            99_000_003,
        );

        CharacterStanding::query()->create([
            'owner_type' => CharacterStanding::OWNER_CORPORATION,
            'owner_id' => $viewerContext->viewer_corporation_id,
            'contact_id' => 12345,
            'contact_type' => 'character',
            'standing' => '10.0',
            'source_character_id' => $viewerContext->character_id,
            'synced_at' => CarbonImmutable::now(),
        ]);

        self::assertFalse($classification->refresh()->is_dirty);
    }

    /**
     * @return array{ViewerContext, ViewerEntityClassification}
     */
    private function makeClassification(string $targetType, int $targetId): array
    {
        $bloc = CoalitionBloc::query()->firstOrCreate(
            ['bloc_code' => 'wc'],
            ['display_name' => 'WinterCo', 'is_active' => true],
        );

        $character = Character::query()->firstOrCreate(
            ['character_id' => 90_000_001],
            ['name' => 'Test Donor', 'corporation_id' => 98_000_001, 'alliance_id' => 99_000_001, 'user_id' => null],
        );

        $viewerContext = ViewerContext::query()->firstOrCreate(
            ['character_id' => $character->id],
            [
                'viewer_corporation_id' => $character->corporation_id,
                'viewer_alliance_id' => $character->alliance_id,
                'bloc_id' => $bloc->id,
                'bloc_confidence_band' => ViewerContext::CONFIDENCE_HIGH,
                'bloc_unresolved' => false,
                'subscription_status' => ViewerContext::SUBSCRIPTION_ACTIVE,
                'is_active' => true,
            ],
        );

        $classification = ViewerEntityClassification::query()->create([
            'viewer_context_id' => $viewerContext->id,
            'target_entity_type' => $targetType,
            'target_entity_id' => $targetId,
            'resolved_alignment' => ViewerEntityClassification::ALIGNMENT_UNKNOWN,
            'confidence_band' => ViewerEntityClassification::CONFIDENCE_LOW,
            'reason_summary' => 'Seed for observer test.',
            'is_dirty' => false,
            'computed_at' => now(),
        ]);

        return [$viewerContext, $classification];
    }
}

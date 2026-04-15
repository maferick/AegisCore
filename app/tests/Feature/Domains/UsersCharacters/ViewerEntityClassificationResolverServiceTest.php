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
use App\Domains\UsersCharacters\Models\ViewerEntityClassificationHistory;
use App\Domains\UsersCharacters\Services\ViewerEntityClassificationResolverService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

final class ViewerEntityClassificationResolverServiceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_viewer_override_wins_and_writes_history(): void
    {
        [$viewerContext, $viewerBloc, $memberType] = $this->makeViewerContext();
        $targetAllianceId = 99_000_001;

        CoalitionEntityLabel::query()->create([
            'entity_type' => CoalitionEntityLabel::ENTITY_ALLIANCE,
            'entity_id' => $targetAllianceId,
            'raw_label' => 'wc.member',
            'bloc_id' => $viewerBloc->id,
            'relationship_type_id' => $memberType->id,
            'source' => CoalitionEntityLabel::SOURCE_MANUAL,
            'is_active' => true,
        ]);

        EntityClassificationOverride::query()->create([
            'scope_type' => EntityClassificationOverride::SCOPE_VIEWER,
            'viewer_context_id' => $viewerContext->id,
            'target_entity_type' => ViewerEntityClassification::ENTITY_ALLIANCE,
            'target_entity_id' => $targetAllianceId,
            'forced_alignment' => ViewerEntityClassification::ALIGNMENT_HOSTILE,
            'reason' => 'Donor-local diplomatic exception.',
            'is_active' => true,
        ]);

        $service = new ViewerEntityClassificationResolverService();

        $resolved = $service->resolveForTarget(
            $viewerContext,
            ViewerEntityClassification::ENTITY_ALLIANCE,
            $targetAllianceId,
        );

        self::assertSame(ViewerEntityClassification::ALIGNMENT_HOSTILE, $resolved->resolved_alignment);
        self::assertSame(ViewerEntityClassification::CONFIDENCE_HIGH, $resolved->confidence_band);
        self::assertStringContainsString('Viewer override applied', $resolved->reason_summary);

        $history = ViewerEntityClassificationHistory::query()->sole();
        self::assertNull($history->old_alignment);
        self::assertSame(ViewerEntityClassification::ALIGNMENT_HOSTILE, $history->new_alignment);
    }

    public function test_direct_standing_beats_global_override(): void
    {
        [$viewerContext] = $this->makeViewerContext();
        $targetCorpId = 98_000_123;

        CharacterStanding::query()->create([
            'owner_type' => CharacterStanding::OWNER_CORPORATION,
            'owner_id' => $viewerContext->viewer_corporation_id,
            'contact_id' => $targetCorpId,
            'contact_type' => CharacterStanding::CONTACT_CORPORATION,
            'standing' => '8.2',
            'source_character_id' => $viewerContext->character_id,
            'synced_at' => CarbonImmutable::parse('2026-04-15T00:00:00Z'),
        ]);

        EntityClassificationOverride::query()->create([
            'scope_type' => EntityClassificationOverride::SCOPE_GLOBAL,
            'viewer_context_id' => null,
            'target_entity_type' => ViewerEntityClassification::ENTITY_CORPORATION,
            'target_entity_id' => $targetCorpId,
            'forced_alignment' => ViewerEntityClassification::ALIGNMENT_HOSTILE,
            'reason' => 'Global emergency override that should lose to direct standing.',
            'is_active' => true,
        ]);

        $service = new ViewerEntityClassificationResolverService();
        $resolved = $service->resolveForTarget(
            $viewerContext,
            ViewerEntityClassification::ENTITY_CORPORATION,
            $targetCorpId,
        );

        self::assertSame(ViewerEntityClassification::ALIGNMENT_FRIENDLY, $resolved->resolved_alignment);
        self::assertStringContainsString('Viewer standing evidence', $resolved->reason_summary);
    }

    public function test_conflicting_corp_and_alliance_standings_set_needs_review(): void
    {
        [$viewerContext] = $this->makeViewerContext();
        $targetAllianceId = 99_000_321;

        CharacterStanding::query()->create([
            'owner_type' => CharacterStanding::OWNER_CORPORATION,
            'owner_id' => $viewerContext->viewer_corporation_id,
            'contact_id' => $targetAllianceId,
            'contact_type' => CharacterStanding::CONTACT_ALLIANCE,
            'standing' => '9.0',
            'source_character_id' => $viewerContext->character_id,
            'synced_at' => CarbonImmutable::parse('2026-04-15T00:00:00Z'),
        ]);

        CharacterStanding::query()->create([
            'owner_type' => CharacterStanding::OWNER_ALLIANCE,
            'owner_id' => $viewerContext->viewer_alliance_id,
            'contact_id' => $targetAllianceId,
            'contact_type' => CharacterStanding::CONTACT_ALLIANCE,
            'standing' => '-9.0',
            'source_character_id' => $viewerContext->character_id,
            'synced_at' => CarbonImmutable::parse('2026-04-15T00:00:00Z'),
        ]);

        $service = new ViewerEntityClassificationResolverService();
        $resolved = $service->resolveForTarget(
            $viewerContext,
            ViewerEntityClassification::ENTITY_ALLIANCE,
            $targetAllianceId,
        );

        self::assertSame(ViewerEntityClassification::ALIGNMENT_FRIENDLY, $resolved->resolved_alignment);
        self::assertTrue($resolved->needs_review);
        self::assertStringContainsString('Conflicting standings exist', $resolved->reason_summary);
        self::assertIsArray($resolved->evidence_snapshot);
        self::assertNotEmpty($resolved->evidence_snapshot['conflicting_owner_level_evidence']);
    }

    public function test_global_override_beats_label_match(): void
    {
        [$viewerContext, $viewerBloc, $memberType] = $this->makeViewerContext();

        $enemyBloc = CoalitionBloc::query()->create([
            'bloc_code' => 'enemy-bloc',
            'display_name' => 'Enemy Bloc',
            'default_role' => CoalitionBloc::ROLE_COMBAT,
            'is_active' => true,
        ]);

        $targetAllianceId = 99_000_444;

        CoalitionEntityLabel::query()->create([
            'entity_type' => CoalitionEntityLabel::ENTITY_ALLIANCE,
            'entity_id' => $targetAllianceId,
            'raw_label' => 'enemy.member',
            'bloc_id' => $enemyBloc->id,
            'relationship_type_id' => $memberType->id,
            'source' => CoalitionEntityLabel::SOURCE_MANUAL,
            'is_active' => true,
        ]);

        EntityClassificationOverride::query()->create([
            'scope_type' => EntityClassificationOverride::SCOPE_GLOBAL,
            'viewer_context_id' => null,
            'target_entity_type' => ViewerEntityClassification::ENTITY_ALLIANCE,
            'target_entity_id' => $targetAllianceId,
            'forced_alignment' => ViewerEntityClassification::ALIGNMENT_NEUTRAL,
            'reason' => 'Temporary diplomacy freeze.',
            'is_active' => true,
        ]);

        $service = new ViewerEntityClassificationResolverService();
        $resolved = $service->resolveForTarget(
            $viewerContext,
            ViewerEntityClassification::ENTITY_ALLIANCE,
            $targetAllianceId,
        );

        self::assertNotSame($viewerBloc->id, $enemyBloc->id);
        self::assertSame(ViewerEntityClassification::ALIGNMENT_NEUTRAL, $resolved->resolved_alignment);
        self::assertStringContainsString('Global override applied', $resolved->reason_summary);
    }

    public function test_stale_affiliation_profile_downgrades_inherited_confidence(): void
    {
        [$viewerContext, $viewerBloc, $memberType] = $this->makeViewerContext();
        $targetCorpId = 98_000_777;
        $allianceId = 99_000_777;

        CoalitionEntityLabel::query()->create([
            'entity_type' => CoalitionEntityLabel::ENTITY_ALLIANCE,
            'entity_id' => $allianceId,
            'raw_label' => 'wc.member',
            'bloc_id' => $viewerBloc->id,
            'relationship_type_id' => $memberType->id,
            'source' => CoalitionEntityLabel::SOURCE_MANUAL,
            'is_active' => true,
        ]);

        CorporationAffiliationProfile::query()->create([
            'corporation_id' => $targetCorpId,
            'current_alliance_id' => $allianceId,
            'previous_alliance_id' => null,
            'last_alliance_change_at' => null,
            'recently_changed_affiliation' => false,
            'history_confidence_band' => CorporationAffiliationProfile::CONFIDENCE_HIGH,
            'observed_at' => CarbonImmutable::parse('2026-02-01T00:00:00Z'),
        ]);

        $service = new ViewerEntityClassificationResolverService();
        $resolved = $service->resolveForTarget(
            $viewerContext,
            ViewerEntityClassification::ENTITY_CORPORATION,
            $targetCorpId,
        );

        self::assertSame(ViewerEntityClassification::ALIGNMENT_FRIENDLY, $resolved->resolved_alignment);
        self::assertSame(ViewerEntityClassification::CONFIDENCE_LOW, $resolved->confidence_band);
        self::assertTrue($resolved->needs_review);
        self::assertStringContainsString('stale', $resolved->reason_summary);
    }

    public function test_fallback_uses_unknown_when_viewer_bloc_is_unset(): void
    {
        [$viewerContext] = $this->makeViewerContext();
        $viewerContext->bloc_id = null;
        $viewerContext->save();

        $service = new ViewerEntityClassificationResolverService();
        $resolved = $service->resolveForTarget(
            $viewerContext->refresh(),
            ViewerEntityClassification::ENTITY_ALLIANCE,
            99_123_456,
        );

        self::assertSame(ViewerEntityClassification::ALIGNMENT_UNKNOWN, $resolved->resolved_alignment);
        self::assertStringContainsString('Fallback', $resolved->reason_summary);
    }

    /**
     * @return array{ViewerContext, CoalitionBloc, CoalitionRelationshipType}
     */
    private function makeViewerContext(): array
    {
        $bloc = CoalitionBloc::query()->create([
            'bloc_code' => 'wc',
            'display_name' => 'WinterCo',
            'default_role' => CoalitionBloc::ROLE_COMBAT,
            'is_active' => true,
        ]);

        $memberType = CoalitionRelationshipType::query()->create([
            'relationship_code' => 'member',
            'display_name' => 'Member',
            'default_role' => 'combat',
            'inherits_alignment' => true,
            'display_order' => 10,
        ]);

        $character = Character::query()->create([
            'character_id' => 90_000_001,
            'name' => 'Test Donor Character',
            'corporation_id' => 98_000_001,
            'alliance_id' => 99_000_001,
            'user_id' => null,
        ]);

        $viewerContext = ViewerContext::query()->create([
            'character_id' => $character->id,
            'viewer_corporation_id' => $character->corporation_id,
            'viewer_alliance_id' => $character->alliance_id,
            'bloc_id' => $bloc->id,
            'bloc_confidence_band' => ViewerContext::CONFIDENCE_HIGH,
            'bloc_unresolved' => false,
            'subscription_status' => ViewerContext::SUBSCRIPTION_ACTIVE,
            'is_active' => true,
        ]);

        return [$viewerContext, $bloc, $memberType];
    }
}

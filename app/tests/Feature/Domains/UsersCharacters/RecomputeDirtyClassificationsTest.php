<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\UsersCharacters;

use App\Domains\UsersCharacters\Jobs\RecomputeDirtyClassifications;
use App\Domains\UsersCharacters\Models\Character;
use App\Domains\UsersCharacters\Models\CoalitionBloc;
use App\Domains\UsersCharacters\Models\ViewerContext;
use App\Domains\UsersCharacters\Models\ViewerEntityClassification;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * Verifies that the {@see RecomputeDirtyClassifications} job resolves
 * dirty classification rows and clears the dirty flag.
 */
final class RecomputeDirtyClassificationsTest extends TestCase
{
    use DatabaseMigrations;

    public function test_job_resolves_dirty_rows_and_clears_flag(): void
    {
        [$viewerContext] = $this->makeViewerContext();

        $classification = ViewerEntityClassification::query()->create([
            'viewer_context_id' => $viewerContext->id,
            'target_entity_type' => ViewerEntityClassification::ENTITY_ALLIANCE,
            'target_entity_id' => 99_999_001,
            'resolved_alignment' => ViewerEntityClassification::ALIGNMENT_UNKNOWN,
            'confidence_band' => ViewerEntityClassification::CONFIDENCE_LOW,
            'reason_summary' => 'Stale seed.',
            'is_dirty' => true,
            'computed_at' => now()->subDays(10),
        ]);

        self::assertTrue($classification->is_dirty);

        $job = new RecomputeDirtyClassifications($viewerContext->id);
        $job->handle(new \App\Domains\UsersCharacters\Services\ViewerEntityClassificationResolverService());

        $classification->refresh();
        self::assertFalse($classification->is_dirty);
        self::assertNotNull($viewerContext->refresh()->last_recomputed_at);
    }

    public function test_job_skips_inactive_viewer_context(): void
    {
        [$viewerContext] = $this->makeViewerContext();
        $viewerContext->update(['is_active' => false]);

        ViewerEntityClassification::query()->create([
            'viewer_context_id' => $viewerContext->id,
            'target_entity_type' => ViewerEntityClassification::ENTITY_ALLIANCE,
            'target_entity_id' => 99_999_002,
            'resolved_alignment' => ViewerEntityClassification::ALIGNMENT_UNKNOWN,
            'confidence_band' => ViewerEntityClassification::CONFIDENCE_LOW,
            'reason_summary' => 'Seed.',
            'is_dirty' => true,
            'computed_at' => now()->subDays(10),
        ]);

        $job = new RecomputeDirtyClassifications($viewerContext->id);
        $job->handle(new \App\Domains\UsersCharacters\Services\ViewerEntityClassificationResolverService());

        // Row stays dirty — job bailed out.
        self::assertTrue(
            ViewerEntityClassification::query()
                ->where('viewer_context_id', $viewerContext->id)
                ->first()
                ->is_dirty,
        );
    }

    /**
     * @return array{ViewerContext}
     */
    private function makeViewerContext(): array
    {
        $bloc = CoalitionBloc::query()->create([
            'bloc_code' => 'wc',
            'display_name' => 'WinterCo',
            'is_active' => true,
        ]);

        $character = Character::query()->create([
            'character_id' => 90_000_001,
            'name' => 'Test Donor',
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

        return [$viewerContext];
    }
}

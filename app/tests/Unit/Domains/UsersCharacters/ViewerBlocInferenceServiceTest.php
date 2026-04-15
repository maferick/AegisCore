<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\UsersCharacters;

use App\Domains\UsersCharacters\Models\CoalitionEntityLabel;
use App\Domains\UsersCharacters\Models\ViewerContext;
use App\Domains\UsersCharacters\Services\ViewerBlocInferenceInput;
use App\Domains\UsersCharacters\Services\ViewerBlocInferenceService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure viewer-bloc inference logic. No DB — labels
 * are constructed as in-memory model instances and fed directly to the
 * service, which is the whole reason the service was designed to take
 * a preloaded collection.
 */
final class ViewerBlocInferenceServiceTest extends TestCase
{
    private const BLOC_WC = 1;

    private const BLOC_B2 = 2;

    private const ALLIANCE_ID = 99000001;

    private const CORP_ID = 98000001;

    public function test_resolves_high_from_alliance_label(): void
    {
        $service = new ViewerBlocInferenceService();
        $result = $service->infer(new ViewerBlocInferenceInput(
            viewerAllianceId: self::ALLIANCE_ID,
            viewerCorporationId: self::CORP_ID,
            labels: $this->labels([
                $this->label(CoalitionEntityLabel::ENTITY_ALLIANCE, self::ALLIANCE_ID, 'wc.member', self::BLOC_WC),
            ]),
        ));

        $this->assertTrue($result->resolved);
        $this->assertSame(self::BLOC_WC, $result->blocId);
        $this->assertSame(ViewerContext::CONFIDENCE_HIGH, $result->confidenceBand);
        $this->assertStringContainsString('wc.member', $result->reason);
    }

    public function test_resolves_high_from_corporation_label_when_alliance_has_none(): void
    {
        $service = new ViewerBlocInferenceService();
        $result = $service->infer(new ViewerBlocInferenceInput(
            viewerAllianceId: self::ALLIANCE_ID,
            viewerCorporationId: self::CORP_ID,
            labels: $this->labels([
                $this->label(CoalitionEntityLabel::ENTITY_CORPORATION, self::CORP_ID, 'b2.member', self::BLOC_B2),
            ]),
        ));

        $this->assertTrue($result->resolved);
        $this->assertSame(self::BLOC_B2, $result->blocId);
        $this->assertSame(ViewerContext::CONFIDENCE_HIGH, $result->confidenceBand);
        $this->assertStringContainsString('b2.member', $result->reason);
    }

    public function test_alliance_wins_over_corp_with_medium_confidence_on_conflict(): void
    {
        $service = new ViewerBlocInferenceService();
        $result = $service->infer(new ViewerBlocInferenceInput(
            viewerAllianceId: self::ALLIANCE_ID,
            viewerCorporationId: self::CORP_ID,
            labels: $this->labels([
                $this->label(CoalitionEntityLabel::ENTITY_ALLIANCE, self::ALLIANCE_ID, 'wc.member', self::BLOC_WC),
                $this->label(CoalitionEntityLabel::ENTITY_CORPORATION, self::CORP_ID, 'b2.member', self::BLOC_B2),
            ]),
        ));

        $this->assertTrue($result->resolved);
        $this->assertSame(self::BLOC_WC, $result->blocId);
        $this->assertSame(ViewerContext::CONFIDENCE_MEDIUM, $result->confidenceBand);
        $this->assertStringContainsString('disagree', $result->reason);
    }

    public function test_unresolved_when_alliance_labels_point_at_multiple_blocs(): void
    {
        $service = new ViewerBlocInferenceService();
        $result = $service->infer(new ViewerBlocInferenceInput(
            viewerAllianceId: self::ALLIANCE_ID,
            viewerCorporationId: self::CORP_ID,
            labels: $this->labels([
                $this->label(CoalitionEntityLabel::ENTITY_ALLIANCE, self::ALLIANCE_ID, 'wc.member', self::BLOC_WC),
                $this->label(CoalitionEntityLabel::ENTITY_ALLIANCE, self::ALLIANCE_ID, 'b2.affiliate', self::BLOC_B2),
            ]),
        ));

        $this->assertFalse($result->resolved);
        $this->assertNull($result->blocId);
        $this->assertNull($result->confidenceBand);
        $this->assertStringContainsString('multiple blocs', $result->reason);
    }

    public function test_unresolved_when_no_labels_exist(): void
    {
        $service = new ViewerBlocInferenceService();
        $result = $service->infer(new ViewerBlocInferenceInput(
            viewerAllianceId: self::ALLIANCE_ID,
            viewerCorporationId: self::CORP_ID,
            labels: $this->labels([]),
        ));

        $this->assertFalse($result->resolved);
        $this->assertNull($result->blocId);
    }

    public function test_inactive_labels_are_ignored(): void
    {
        $service = new ViewerBlocInferenceService();
        $result = $service->infer(new ViewerBlocInferenceInput(
            viewerAllianceId: self::ALLIANCE_ID,
            viewerCorporationId: self::CORP_ID,
            labels: $this->labels([
                $this->label(CoalitionEntityLabel::ENTITY_ALLIANCE, self::ALLIANCE_ID, 'wc.member', self::BLOC_WC, isActive: false),
            ]),
        ));

        $this->assertFalse($result->resolved);
    }

    public function test_labels_without_bloc_id_are_ignored(): void
    {
        $service = new ViewerBlocInferenceService();
        $result = $service->infer(new ViewerBlocInferenceInput(
            viewerAllianceId: self::ALLIANCE_ID,
            viewerCorporationId: self::CORP_ID,
            labels: $this->labels([
                $this->label(CoalitionEntityLabel::ENTITY_ALLIANCE, self::ALLIANCE_ID, 'mystery.tag', null),
            ]),
        ));

        $this->assertFalse($result->resolved);
        $this->assertStringContainsString('No coalition labels', $result->reason);
    }

    public function test_labels_for_other_entities_are_ignored(): void
    {
        $service = new ViewerBlocInferenceService();
        $result = $service->infer(new ViewerBlocInferenceInput(
            viewerAllianceId: self::ALLIANCE_ID,
            viewerCorporationId: self::CORP_ID,
            labels: $this->labels([
                // An unrelated alliance's label leaked into the preload
                $this->label(CoalitionEntityLabel::ENTITY_ALLIANCE, 99999999, 'wc.member', self::BLOC_WC),
            ]),
        ));

        $this->assertFalse($result->resolved);
    }

    public function test_null_alliance_and_null_corp_yields_unresolved(): void
    {
        $service = new ViewerBlocInferenceService();
        $result = $service->infer(new ViewerBlocInferenceInput(
            viewerAllianceId: null,
            viewerCorporationId: null,
            labels: $this->labels([
                $this->label(CoalitionEntityLabel::ENTITY_ALLIANCE, self::ALLIANCE_ID, 'wc.member', self::BLOC_WC),
            ]),
        ));

        $this->assertFalse($result->resolved);
    }

    public function test_corp_label_used_when_alliance_id_null(): void
    {
        $service = new ViewerBlocInferenceService();
        $result = $service->infer(new ViewerBlocInferenceInput(
            viewerAllianceId: null,
            viewerCorporationId: self::CORP_ID,
            labels: $this->labels([
                $this->label(CoalitionEntityLabel::ENTITY_CORPORATION, self::CORP_ID, 'wc.member', self::BLOC_WC),
            ]),
        ));

        $this->assertTrue($result->resolved);
        $this->assertSame(self::BLOC_WC, $result->blocId);
    }

    public function test_matching_alliance_label_and_corp_label_same_bloc_is_high_confidence(): void
    {
        // Alliance AND corp both labelled into same bloc — we honour
        // the alliance label (tightest signal) at high confidence.
        // No "conflict" should be emitted.
        $service = new ViewerBlocInferenceService();
        $result = $service->infer(new ViewerBlocInferenceInput(
            viewerAllianceId: self::ALLIANCE_ID,
            viewerCorporationId: self::CORP_ID,
            labels: $this->labels([
                $this->label(CoalitionEntityLabel::ENTITY_ALLIANCE, self::ALLIANCE_ID, 'wc.member', self::BLOC_WC),
                $this->label(CoalitionEntityLabel::ENTITY_CORPORATION, self::CORP_ID, 'wc.member', self::BLOC_WC),
            ]),
        ));

        $this->assertTrue($result->resolved);
        $this->assertSame(self::BLOC_WC, $result->blocId);
        $this->assertSame(ViewerContext::CONFIDENCE_HIGH, $result->confidenceBand);
        $this->assertStringNotContainsString('disagree', $result->reason);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param list<CoalitionEntityLabel> $items */
    private function labels(array $items): Collection
    {
        return collect($items);
    }

    private function label(
        string $entityType,
        int $entityId,
        string $rawLabel,
        ?int $blocId,
        bool $isActive = true,
    ): CoalitionEntityLabel {
        $label = new CoalitionEntityLabel([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'raw_label' => $rawLabel,
            'bloc_id' => $blocId,
            'is_active' => $isActive,
            'source' => CoalitionEntityLabel::SOURCE_MANUAL,
        ]);
        // Laravel won't cast/hydrate these attributes until the model
        // is saved; set them directly so the service sees typed values.
        $label->entity_type = $entityType;
        $label->entity_id = $entityId;
        $label->raw_label = $rawLabel;
        $label->bloc_id = $blocId;
        $label->is_active = $isActive;

        return $label;
    }
}

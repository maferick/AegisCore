<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\UsersCharacters;

use App\Domains\UsersCharacters\Models\EntityClassificationOverride;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * Unit-level checks for the {@see EntityClassificationOverride} scope
 * invariant:
 *
 *     scope = 'global'  <=>  viewer_context_id IS NULL
 *     scope = 'viewer'  <=>  viewer_context_id IS NOT NULL
 *
 * The invariant is enforced in two places: the saving hook on the
 * model, and the pure-static {@see EntityClassificationOverride::assertValidScope()}
 * helper that the hook delegates to. This test exercises the helper
 * directly so the rule can be verified without a DB round-trip (the
 * feature-level test suite needs a real MariaDB, which isn't what a
 * unit test should require).
 */
final class EntityClassificationOverrideTest extends TestCase
{
    public function test_global_scope_with_no_viewer_context_is_valid(): void
    {
        EntityClassificationOverride::assertValidScope(
            EntityClassificationOverride::SCOPE_GLOBAL,
            null,
        );

        // assertValidScope returns void on success; reaching here is
        // the assertion. PHPUnit otherwise flags the test as risky
        // (no assertions).
        $this->expectNotToPerformAssertions();
    }

    public function test_viewer_scope_with_viewer_context_is_valid(): void
    {
        EntityClassificationOverride::assertValidScope(
            EntityClassificationOverride::SCOPE_VIEWER,
            42,
        );

        $this->expectNotToPerformAssertions();
    }

    public function test_global_scope_with_viewer_context_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Global override must not have a viewer_context_id');

        EntityClassificationOverride::assertValidScope(
            EntityClassificationOverride::SCOPE_GLOBAL,
            42,
        );
    }

    public function test_viewer_scope_without_viewer_context_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Viewer override must have a viewer_context_id');

        EntityClassificationOverride::assertValidScope(
            EntityClassificationOverride::SCOPE_VIEWER,
            null,
        );
    }
}

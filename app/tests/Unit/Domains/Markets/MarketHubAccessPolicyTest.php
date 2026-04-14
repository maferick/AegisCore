<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Markets;

use App\Domains\Markets\Models\MarketHub;
use App\Domains\Markets\Models\MarketHubEntitlement;
use App\Domains\Markets\Services\MarketHubAccessPolicy;
use App\Domains\UsersCharacters\Models\Character;
use App\Domains\UsersCharacters\Models\EveDonorBenefit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * Policy-layer verification for ADR-0005's intersection rule.
 *
 * The rule is the whole feature's security boundary — everything
 * else (Livewire registration, Filament audit, Python poller) trusts
 * this service to be correct. Every test here is a one-sentence
 * product statement expressed as a boolean:
 *
 *   - Free user can see Jita.
 *   - Free user cannot see a private hub even if entitled.
 *   - Donor cannot see a private hub they have no entitlement for.
 *   - Donor can see a private hub they have an entitlement for.
 *   - Admin (even non-donor) can see a private hub they are entitled to.
 *   - Admin (even non-donor) cannot see a private hub they are not entitled to.
 *   - An inactive private hub is invisible even to entitled donors.
 *   - The query-builder scope (visibleHubsFor) agrees with canView().
 *
 * These are not redundant with docblocks — ADR-0005 explicitly says
 * canView() and visibleHubsFor() must stay in lockstep, and the only
 * way to enforce that invariant is a test that forces both to agree
 * on the same fixture set.
 */
final class MarketHubAccessPolicyTest extends TestCase
{
    use DatabaseMigrations;

    private MarketHubAccessPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new MarketHubAccessPolicy;
    }

    public function test_public_reference_hub_is_visible_to_everyone(): void
    {
        $jita = $this->jitaHub();
        $freeUser = $this->makeUser();

        self::assertTrue($this->policy->canView($freeUser, $jita));
        self::assertTrue($this->policy->hasHubAccess($freeUser, $jita));
    }

    public function test_free_user_cannot_see_a_private_hub_even_if_entitled(): void
    {
        $freeUser = $this->makeUser();
        $hub = $this->privateHub();

        // Even a direct entitlement row does not bypass the feature
        // gate. This is the "one donor unlocks market intel for a
        // non-donor" prevention the product spec locks in.
        MarketHubEntitlement::create([
            'hub_id' => $hub->id,
            'subject_type' => MarketHubEntitlement::SUBJECT_TYPE_USER,
            'subject_id' => $freeUser->id,
            'granted_by_user_id' => $freeUser->id,
            'granted_at' => now(),
        ]);

        self::assertFalse($this->policy->canView($freeUser, $hub));
    }

    public function test_donor_without_entitlement_cannot_see_private_hub(): void
    {
        $donor = $this->makeDonor();
        $hub = $this->privateHub();

        self::assertTrue($this->policy->hasFeatureAccess($donor));
        self::assertFalse($this->policy->hasHubAccess($donor, $hub));
        self::assertFalse($this->policy->canView($donor, $hub));
    }

    public function test_donor_with_entitlement_can_see_private_hub(): void
    {
        $donor = $this->makeDonor();
        $hub = $this->privateHub();

        MarketHubEntitlement::create([
            'hub_id' => $hub->id,
            'subject_type' => MarketHubEntitlement::SUBJECT_TYPE_USER,
            'subject_id' => $donor->id,
            'granted_by_user_id' => $donor->id,
            'granted_at' => now(),
        ]);

        self::assertTrue($this->policy->canView($donor, $hub));
    }

    public function test_admin_with_entitlement_can_see_private_hub_even_without_donation(): void
    {
        // Admin is a non-donor here — feature gate satisfied via
        // isAdmin(), not isDonor().
        $admin = $this->makeAdmin();
        $hub = $this->privateHub();

        MarketHubEntitlement::create([
            'hub_id' => $hub->id,
            'subject_type' => MarketHubEntitlement::SUBJECT_TYPE_USER,
            'subject_id' => $admin->id,
            'granted_by_user_id' => $admin->id,
            'granted_at' => now(),
        ]);

        self::assertTrue($this->policy->canView($admin, $hub));
    }

    public function test_admin_without_entitlement_cannot_see_private_hub(): void
    {
        // Admins are NOT implicitly entitled to every hub — they
        // must grant themselves an entitlement, which leaves an
        // audit trail. This is ADR-0005 § Admin bypass.
        $admin = $this->makeAdmin();
        $hub = $this->privateHub();

        self::assertFalse($this->policy->canView($admin, $hub));
    }

    public function test_inactive_private_hub_is_invisible_even_to_entitled_donor(): void
    {
        $donor = $this->makeDonor();
        $hub = $this->privateHub();
        $hub->update(['is_active' => false, 'disabled_reason' => 'test']);

        MarketHubEntitlement::create([
            'hub_id' => $hub->id,
            'subject_type' => MarketHubEntitlement::SUBJECT_TYPE_USER,
            'subject_id' => $donor->id,
            'granted_by_user_id' => $donor->id,
            'granted_at' => now(),
        ]);

        self::assertFalse($this->policy->canView($donor, $hub));
    }

    public function test_public_reference_hub_remains_visible_to_free_user_even_when_inactive_flag_logic_would_say_otherwise(): void
    {
        // Public-reference hubs short-circuit before the is_active
        // check. Jita being paused is an operator concern visible in
        // /admin, not a reason to hide it from readers (who already
        // see "stale" signals via last_sync_at).
        $jita = $this->jitaHub();
        $jita->update(['is_active' => false]);
        $freeUser = $this->makeUser();

        self::assertTrue($this->policy->canView($freeUser, $jita));
    }

    public function test_visible_hubs_for_free_user_only_includes_public_reference(): void
    {
        $jita = $this->jitaHub();
        $otherPublic = $this->publicHub(10_000_043, 60_011_866, 'Dodixie');
        $private = $this->privateHub();
        $freeUser = $this->makeUser();

        // Grant the free user a (dormant, feature-gated) entitlement —
        // should still not show up.
        MarketHubEntitlement::create([
            'hub_id' => $private->id,
            'subject_type' => MarketHubEntitlement::SUBJECT_TYPE_USER,
            'subject_id' => $freeUser->id,
            'granted_by_user_id' => $freeUser->id,
            'granted_at' => now(),
        ]);

        $visibleIds = $this->policy->visibleHubsFor($freeUser)->pluck('id')->all();

        self::assertContains($jita->id, $visibleIds);
        self::assertContains($otherPublic->id, $visibleIds);
        self::assertNotContains($private->id, $visibleIds);
    }

    public function test_visible_hubs_for_donor_includes_public_reference_and_entitled_private_only(): void
    {
        $jita = $this->jitaHub();
        $entitled = $this->privateHub(structureId: 1_035_466_617_946, name: 'Keepstar A');
        $notEntitled = $this->privateHub(structureId: 1_035_466_617_947, name: 'Keepstar B');
        $inactive = $this->privateHub(structureId: 1_035_466_617_948, name: 'Keepstar C');
        $inactive->update(['is_active' => false]);

        $donor = $this->makeDonor();

        // Entitle for two: one active, one inactive.
        foreach ([$entitled, $inactive] as $hub) {
            MarketHubEntitlement::create([
                'hub_id' => $hub->id,
                'subject_type' => MarketHubEntitlement::SUBJECT_TYPE_USER,
                'subject_id' => $donor->id,
                'granted_by_user_id' => $donor->id,
                'granted_at' => now(),
            ]);
        }

        $visibleIds = $this->policy->visibleHubsFor($donor)->pluck('id')->all();

        self::assertContains($jita->id, $visibleIds);
        self::assertContains($entitled->id, $visibleIds);
        self::assertNotContains($notEntitled->id, $visibleIds);
        // Inactive hubs are filtered by visibleHubsFor() — the
        // is_active clause is applied BEFORE the feature / entitlement
        // split, matching canView().
        self::assertNotContains($inactive->id, $visibleIds);
    }

    public function test_visible_hubs_for_stays_in_lockstep_with_can_view(): void
    {
        // ADR-0005 invariant: canView() and visibleHubsFor() must
        // agree on the same fixture set or the UI will list hubs that
        // then refuse to open. This test iterates both sides of the
        // contract and asserts membership equality.
        $jita = $this->jitaHub();
        $private1 = $this->privateHub(structureId: 1_035_000_000_001, name: 'Private 1');
        $private2 = $this->privateHub(structureId: 1_035_000_000_002, name: 'Private 2');
        $donor = $this->makeDonor();

        MarketHubEntitlement::create([
            'hub_id' => $private1->id,
            'subject_type' => MarketHubEntitlement::SUBJECT_TYPE_USER,
            'subject_id' => $donor->id,
            'granted_by_user_id' => $donor->id,
            'granted_at' => now(),
        ]);

        $allHubs = MarketHub::all();
        $canViewIds = $allHubs->filter(fn (MarketHub $h) => $this->policy->canView($donor, $h))->pluck('id')->sort()->values()->all();
        $scopedIds = $this->policy->visibleHubsFor($donor)->pluck('id')->sort()->values()->all();

        self::assertSame($canViewIds, $scopedIds, 'canView() and visibleHubsFor() must enumerate the same hub set');
    }

    // -- fixtures ---------------------------------------------------------

    private function makeUser(?string $email = null): User
    {
        static $counter = 0;
        $counter++;

        return User::query()->create([
            'name' => 'Test User '.$counter,
            'email' => $email ?? "user{$counter}@example.test",
            'password' => 'x',
        ]);
    }

    private function makeDonor(): User
    {
        $user = $this->makeUser();
        $charId = 96_000_000 + $user->id;

        Character::query()->create([
            'user_id' => $user->id,
            'character_id' => $charId,
            'name' => 'Donor Char '.$user->id,
        ]);

        EveDonorBenefit::query()->create([
            'donor_character_id' => $charId,
            'ad_free_until' => now()->addDays(30),
            'total_isk_donated' => 3_000_000,
            'donations_count' => 1,
            'first_donated_at' => now()->subDays(1),
            'last_donated_at' => now()->subDays(1),
            'rate_isk_per_day' => 100_000,
            'recomputed_at' => now(),
        ]);

        return $user;
    }

    private function makeAdmin(): User
    {
        // Admin-by-character-allow-list path. Set the linked character
        // on the env-backed config so User::isAdmin() returns true
        // without involving donation state.
        $user = $this->makeUser();
        $charId = 97_000_000 + $user->id;

        Character::query()->create([
            'user_id' => $user->id,
            'character_id' => $charId,
            'name' => 'Admin Char '.$user->id,
        ]);

        config(['eve.sso.admin_character_ids' => [(string) $charId]]);

        return $user;
    }

    private function jitaHub(): MarketHub
    {
        return MarketHub::query()->firstOrCreate(
            [
                'location_type' => MarketHub::LOCATION_TYPE_NPC_STATION,
                'location_id' => MarketHub::JITA_LOCATION_ID,
            ],
            [
                'region_id' => MarketHub::JITA_REGION_ID,
                'structure_name' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
                'is_public_reference' => true,
                'is_active' => true,
            ],
        );
    }

    private function publicHub(int $regionId, int $locationId, string $name): MarketHub
    {
        return MarketHub::create([
            'location_type' => MarketHub::LOCATION_TYPE_NPC_STATION,
            'location_id' => $locationId,
            'region_id' => $regionId,
            'structure_name' => $name,
            'is_public_reference' => true,
            'is_active' => true,
        ]);
    }

    private function privateHub(int $structureId = 1_035_000_000_000, string $name = 'Test Keepstar'): MarketHub
    {
        return MarketHub::create([
            'location_type' => MarketHub::LOCATION_TYPE_PLAYER_STRUCTURE,
            'location_id' => $structureId,
            'region_id' => 10_000_002,
            'structure_name' => $name,
            'is_public_reference' => false,
            'is_active' => true,
        ]);
    }
}

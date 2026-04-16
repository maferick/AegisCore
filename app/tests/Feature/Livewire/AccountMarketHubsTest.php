<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domains\Markets\Models\MarketHub;
use App\Domains\Markets\Models\MarketHubEntitlement;
use App\Domains\UsersCharacters\Models\Character;
use App\Domains\UsersCharacters\Models\EveDonorBenefit;
use App\Livewire\AccountMarketHubs;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Unit\Domains\Markets\MarketHubAccessPolicyTest;

/**
 * End-to-end checks for the ADR-0005 Follow-up #1 page.
 *
 * The access-policy invariant itself is covered by
 * {@see MarketHubAccessPolicyTest}; here
 * we verify the Livewire surface wires it up correctly and that
 * set/clear default respects the intersection rule at write time —
 * not just at render time.
 */
final class AccountMarketHubsTest extends TestCase
{
    use DatabaseMigrations;

    public function test_donor_can_set_private_hub_as_default(): void
    {
        $donor = $this->makeDonor();
        $hub = $this->privateHub();
        $this->entitle($donor, $hub);

        Livewire::actingAs($donor)
            ->test(AccountMarketHubs::class)
            ->call('setDefault', $hub->id)
            ->assertSet('error', null);

        $donor->refresh();
        self::assertSame($hub->id, $donor->default_private_market_hub_id);
    }

    public function test_donor_can_clear_default(): void
    {
        $donor = $this->makeDonor();
        $hub = $this->privateHub();
        $this->entitle($donor, $hub);
        $donor->default_private_market_hub_id = $hub->id;
        $donor->save();

        Livewire::actingAs($donor)
            ->test(AccountMarketHubs::class)
            ->call('clearDefault');

        $donor->refresh();
        self::assertNull($donor->default_private_market_hub_id);
    }

    public function test_free_user_cannot_set_a_private_hub_as_default_even_with_an_entitlement_row(): void
    {
        // Entitlement alone doesn't bypass the feature gate — same
        // rule MarketHubAccessPolicy enforces for visibility. The
        // page surfaces no button for such a user, but a forged
        // POST must also fail server-side.
        $user = $this->makeUser();
        $hub = $this->privateHub();
        $this->entitle($user, $hub);

        Livewire::actingAs($user)
            ->test(AccountMarketHubs::class)
            ->call('setDefault', $hub->id)
            ->assertSet('error', 'Hub not found.');

        $user->refresh();
        self::assertNull($user->default_private_market_hub_id);
    }

    public function test_donor_cannot_set_a_hub_they_are_not_entitled_to_as_default(): void
    {
        $donor = $this->makeDonor();
        $hub = $this->privateHub();
        // No entitlement grant → canView() is false.

        Livewire::actingAs($donor)
            ->test(AccountMarketHubs::class)
            ->call('setDefault', $hub->id)
            ->assertSet('error', 'Hub not found.');

        $donor->refresh();
        self::assertNull($donor->default_private_market_hub_id);
    }

    public function test_public_reference_hub_cannot_be_set_as_default(): void
    {
        // ADR-0005 § User preference: the pin is the donor's
        // *private* comparison target. Jita is always shown
        // alongside — pinning it would be redundant.
        $donor = $this->makeDonor();
        $jita = $this->jitaHub();

        Livewire::actingAs($donor)
            ->test(AccountMarketHubs::class)
            ->call('setDefault', $jita->id)
            ->assertSet('error', fn ($e): bool => is_string($e) && str_contains($e, 'Public-reference hubs'));

        $donor->refresh();
        self::assertNull($donor->default_private_market_hub_id);
    }

    public function test_render_surfaces_entitled_private_hubs_and_hides_others(): void
    {
        $donor = $this->makeDonor();
        $entitled = $this->privateHub(structureId: 1_035_000_000_001, name: 'Keepstar A');
        $hidden = $this->privateHub(structureId: 1_035_000_000_002, name: 'Keepstar B');
        $this->entitle($donor, $entitled);
        $jita = $this->jitaHub();

        Livewire::actingAs($donor)
            ->test(AccountMarketHubs::class)
            ->assertSee('Keepstar A')
            ->assertDontSee('Keepstar B')
            // Jita is a public-reference hub, should appear in the
            // public section for everyone.
            ->assertSee($jita->structure_name);
    }

    // -- fixtures ---------------------------------------------------------

    private function makeUser(): User
    {
        static $counter = 0;
        $counter++;

        $user = User::query()->create([
            'name' => 'Test User '.$counter,
            'email' => "user{$counter}@example.test",
            'password' => 'x',
        ]);

        // Link a non-admin character so User::isAdmin()'s
        // empty-characters escape hatch doesn't bypass the feature
        // gate — we're simulating a real free user, not the
        // bootstrap operator-seed account.
        Character::query()->create([
            'user_id' => $user->id,
            'character_id' => 95_000_000 + $user->id,
            'name' => 'Non-admin Char '.$user->id,
        ]);

        return $user;
    }

    private function makeDonor(): User
    {
        $user = $this->makeUser();
        // Reuse the character makeUser() linked — the donor benefit
        // keys off character_id, so the same row already present on
        // the user is a valid donor anchor.
        $charId = (int) $user->characters()->value('character_id');

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

    private function entitle(User $user, MarketHub $hub): MarketHubEntitlement
    {
        return MarketHubEntitlement::create([
            'hub_id' => $hub->id,
            'subject_type' => MarketHubEntitlement::SUBJECT_TYPE_USER,
            'subject_id' => $user->id,
            'granted_by_user_id' => $user->id,
            'granted_at' => now(),
        ]);
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

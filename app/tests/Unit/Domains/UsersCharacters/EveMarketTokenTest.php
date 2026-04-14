<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\UsersCharacters;

use App\Domains\UsersCharacters\Models\EveMarketToken;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Smoke tests for the EveMarketToken model.
 *
 * Verifies the 'encrypted' cast round-trips through the DB, the
 * foreign-key cascade deletes the token when the owning user is
 * deleted, and scope predicates work.
 *
 * The Python poller's Laravel-encrypter compatibility test already
 * covers the *cryptographic* envelope; this is the Eloquent-side
 * wiring: model casts, hidden columns, scope list JSON round-trip,
 * user-binding cascade.
 */
final class EveMarketTokenTest extends TestCase
{
    use DatabaseMigrations;

    public function test_encrypted_cast_round_trips_through_db(): void
    {
        $user = $this->makeUser();

        $token = EveMarketToken::create([
            'user_id' => $user->id,
            'character_id' => 95_000_001,
            'character_name' => 'Ma Ferick',
            'scopes' => [
                'publicData',
                'esi-markets.structure_markets.v1',
            ],
            'access_token' => 'access_secret_12345',
            'refresh_token' => 'refresh_secret_67890',
            'expires_at' => now()->addMinutes(20),
        ]);

        // Reload from DB — the cast decrypts on the way back.
        $fresh = EveMarketToken::findOrFail($token->id);

        self::assertSame('access_secret_12345', $fresh->access_token);
        self::assertSame('refresh_secret_67890', $fresh->refresh_token);
        self::assertSame(95_000_001, $fresh->character_id);
        self::assertSame($user->id, $fresh->user_id);
        self::assertSame(['publicData', 'esi-markets.structure_markets.v1'], $fresh->scopes);
    }

    public function test_raw_db_value_is_ciphertext_not_plaintext(): void
    {
        $user = $this->makeUser();

        EveMarketToken::create([
            'user_id' => $user->id,
            'character_id' => 95_000_002,
            'character_name' => 'Secret Holder',
            'scopes' => ['publicData', 'esi-markets.structure_markets.v1'],
            'access_token' => 'plaintext_bearer_token',
            'refresh_token' => 'plaintext_refresh_token',
            'expires_at' => now()->addMinutes(20),
        ]);

        // Read the raw column via DB facade — bypasses the Eloquent
        // cast so we see the ciphertext Laravel wrote. This is the
        // security property: a SELECT * leak yields ciphertext, not
        // plaintext bearers.
        $raw = DB::table('eve_market_tokens')->where('character_id', 95_000_002)->first();

        self::assertNotSame('plaintext_bearer_token', $raw->access_token);
        self::assertNotSame('plaintext_refresh_token', $raw->refresh_token);
        // Laravel's envelope is base64 of {"iv":..., "value":..., "mac":..., "tag":""}.
        // We don't assert the exact format (it's tested cryptographically
        // elsewhere); we just verify "plaintext" isn't the stored value.
        self::assertNotEmpty($raw->access_token);
        self::assertNotEmpty($raw->refresh_token);
    }

    public function test_hidden_attributes_excluded_from_array_and_json(): void
    {
        $user = $this->makeUser();

        $token = EveMarketToken::create([
            'user_id' => $user->id,
            'character_id' => 95_000_003,
            'character_name' => 'Ariel',
            'scopes' => ['publicData', 'esi-markets.structure_markets.v1'],
            'access_token' => 'plaintext_bearer',
            'refresh_token' => 'plaintext_refresh',
            'expires_at' => now()->addMinutes(20),
        ]);

        $asArray = $token->toArray();
        self::assertArrayNotHasKey('access_token', $asArray);
        self::assertArrayNotHasKey('refresh_token', $asArray);

        $asJson = $token->toJson();
        self::assertStringNotContainsString('plaintext_bearer', $asJson);
        self::assertStringNotContainsString('plaintext_refresh', $asJson);
    }

    public function test_cascade_deletes_token_when_user_deleted(): void
    {
        $user = $this->makeUser();
        $token = EveMarketToken::create([
            'user_id' => $user->id,
            'character_id' => 95_000_004,
            'character_name' => 'Eph',
            'scopes' => ['publicData', 'esi-markets.structure_markets.v1'],
            'access_token' => 'a',
            'refresh_token' => 'b',
            'expires_at' => now()->addMinutes(20),
        ]);

        $user->delete();

        self::assertNull(EveMarketToken::find($token->id), 'FK cascade should have removed the token');
    }

    public function test_is_access_token_fresh_predicate(): void
    {
        $user = $this->makeUser();

        $fresh = EveMarketToken::create([
            'user_id' => $user->id,
            'character_id' => 95_000_005,
            'character_name' => 'Fresh',
            'scopes' => ['publicData', 'esi-markets.structure_markets.v1'],
            'access_token' => 'a',
            'refresh_token' => 'b',
            'expires_at' => now()->addMinutes(20),  // well beyond the 60s bias
        ]);
        self::assertTrue($fresh->isAccessTokenFresh());

        $stale = EveMarketToken::create([
            'user_id' => $user->id,
            'character_id' => 95_000_006,
            'character_name' => 'Stale',
            'scopes' => ['publicData', 'esi-markets.structure_markets.v1'],
            'access_token' => 'a',
            'refresh_token' => 'b',
            'expires_at' => now()->addSeconds(30), // inside the 60s bias
        ]);
        self::assertFalse($stale->isAccessTokenFresh());
    }

    public function test_has_scope_predicate(): void
    {
        $user = $this->makeUser();
        $token = EveMarketToken::create([
            'user_id' => $user->id,
            'character_id' => 95_000_007,
            'character_name' => 'Scoped',
            'scopes' => ['publicData', 'esi-markets.structure_markets.v1'],
            'access_token' => 'a',
            'refresh_token' => 'b',
            'expires_at' => now()->addMinutes(20),
        ]);

        self::assertTrue($token->hasScope('esi-markets.structure_markets.v1'));
        self::assertTrue($token->hasScope('publicData'));
        self::assertFalse($token->hasScope('esi-wallet.read_character_wallet.v1'));
    }

    public function test_unique_character_id_refuses_second_row(): void
    {
        $user1 = $this->makeUser();
        $user2 = $this->makeUser(email: 'u2@example.test');

        EveMarketToken::create([
            'user_id' => $user1->id,
            'character_id' => 95_000_100,
            'character_name' => 'Alt-linker',
            'scopes' => ['publicData', 'esi-markets.structure_markets.v1'],
            'access_token' => 'a',
            'refresh_token' => 'b',
            'expires_at' => now()->addMinutes(20),
        ]);

        // A second user trying to authorise the SAME character should
        // fail — character_id is UNIQUE. The controller's callback
        // character-linkage check catches this case earlier but the
        // DB constraint is the belt-and-braces.
        $this->expectException(\Illuminate\Database\QueryException::class);
        EveMarketToken::create([
            'user_id' => $user2->id,
            'character_id' => 95_000_100,
            'character_name' => 'Alt-linker',
            'scopes' => ['publicData', 'esi-markets.structure_markets.v1'],
            'access_token' => 'a',
            'refresh_token' => 'b',
            'expires_at' => now()->addMinutes(20),
        ]);
    }

    private function makeUser(string $email = 'donor@example.test'): User
    {
        return User::query()->create([
            'name' => 'Test Donor',
            'email' => $email,
            'password' => 'x',
        ]);
    }
}

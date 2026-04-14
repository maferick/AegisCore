<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Eve;

use App\Domains\UsersCharacters\Models\EveMarketToken;
use App\Models\User;
use App\Services\Eve\MarketTokenAuthorizer;
use App\Services\Eve\Sso\EveSsoClient;
use App\Services\Eve\Sso\EveSsoException;
use App\Services\Eve\Sso\EveSsoRefreshedToken;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Smoke tests for MarketTokenAuthorizer.
 *
 * Key invariants:
 *   1. Fresh token → returns access_token without calling SSO.
 *   2. Stale token → calls SSO.refreshAccessToken(), persists rotated
 *      values, returns new access_token.
 *   3. SSO failure → throws RuntimeException with a user-facing
 *      message (no token update).
 *   4. Missing row (deleted between fetch + lock) → RuntimeException.
 */
final class MarketTokenAuthorizerTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_access_token_without_refresh_when_fresh(): void
    {
        $user = $this->makeUser();
        $token = EveMarketToken::create([
            'user_id' => $user->id,
            'character_id' => 95_000_001,
            'character_name' => 'Fresh',
            'scopes' => ['publicData', 'esi-markets.structure_markets.v1'],
            'access_token' => 'fresh_access',
            'refresh_token' => 'refresh_xxx',
            'expires_at' => now()->addMinutes(20), // well beyond the 60s bias
        ]);

        // SSO should NEVER be called when the token is fresh.
        $sso = Mockery::mock(EveSsoClient::class);
        $sso->shouldNotReceive('refreshAccessToken');

        $authorizer = new MarketTokenAuthorizer($sso);

        self::assertSame('fresh_access', $authorizer->freshAccessToken($token));
    }

    public function test_refreshes_and_persists_when_stale(): void
    {
        $user = $this->makeUser();
        $token = EveMarketToken::create([
            'user_id' => $user->id,
            'character_id' => 95_000_002,
            'character_name' => 'Stale',
            'scopes' => ['publicData', 'esi-markets.structure_markets.v1'],
            'access_token' => 'old_access',
            'refresh_token' => 'old_refresh',
            'expires_at' => now()->addSeconds(30), // inside the 60s bias
        ]);

        $refreshed = new EveSsoRefreshedToken(
            accessToken: 'new_access',
            refreshToken: 'new_refresh',
            expiresIn: 1199,
            characterId: 95_000_002,
            characterName: 'Stale',
            scopes: ['publicData', 'esi-markets.structure_markets.v1'],
        );

        $sso = Mockery::mock(EveSsoClient::class);
        $sso->shouldReceive('refreshAccessToken')
            ->with('old_refresh')
            ->once()
            ->andReturn($refreshed);

        $authorizer = new MarketTokenAuthorizer($sso);

        $result = $authorizer->freshAccessToken($token);

        self::assertSame('new_access', $result);

        // Row should be updated in place — old_access + old_refresh gone.
        $fresh = EveMarketToken::findOrFail($token->id);
        self::assertSame('new_access', $fresh->access_token);
        self::assertSame('new_refresh', $fresh->refresh_token);
        self::assertTrue($fresh->isAccessTokenFresh(), 'expires_at should have been pushed forward');
    }

    public function test_throws_runtime_exception_when_sso_fails(): void
    {
        $user = $this->makeUser();
        $token = EveMarketToken::create([
            'user_id' => $user->id,
            'character_id' => 95_000_003,
            'character_name' => 'Broken',
            'scopes' => ['publicData', 'esi-markets.structure_markets.v1'],
            'access_token' => 'old_access',
            'refresh_token' => 'invalid_refresh',
            'expires_at' => now()->addSeconds(30), // stale
        ]);

        $sso = Mockery::mock(EveSsoClient::class);
        $sso->shouldReceive('refreshAccessToken')
            ->once()
            ->andThrow(new EveSsoException('CCP rejected the refresh (HTTP 400)'));

        $authorizer = new MarketTokenAuthorizer($sso);

        try {
            $authorizer->freshAccessToken($token);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('re-authorise', $e->getMessage());
        }

        // Row should NOT have been updated — the UPDATE only fires on
        // a successful SSO response.
        $stillBroken = EveMarketToken::findOrFail($token->id);
        self::assertSame('old_access', $stillBroken->access_token);
        self::assertSame('invalid_refresh', $stillBroken->refresh_token);
    }

    public function test_throws_when_row_vanishes_before_lock(): void
    {
        $user = $this->makeUser();
        $token = EveMarketToken::create([
            'user_id' => $user->id,
            'character_id' => 95_000_004,
            'character_name' => 'Vanisher',
            'scopes' => ['publicData', 'esi-markets.structure_markets.v1'],
            'access_token' => 'a',
            'refresh_token' => 'b',
            'expires_at' => now()->addMinutes(20),
        ]);

        // Simulate a race: token was loaded, then deleted (e.g. user
        // re-authorised a different character, cascading the old row
        // — or a manual DB cleanup).
        EveMarketToken::where('id', $token->id)->delete();

        $sso = Mockery::mock(EveSsoClient::class);
        $authorizer = new MarketTokenAuthorizer($sso);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('vanished');

        $authorizer->freshAccessToken($token);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Test Donor',
            'email' => 'test-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'x',
        ]);
    }
}

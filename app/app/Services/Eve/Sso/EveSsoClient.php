<?php

declare(strict_types=1);

namespace App\Services\Eve\Sso;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * EVE SSO v2 OAuth2 PKCE client.
 *
 * Handles the three moving parts of the login round-trip:
 *
 *   1. Build the /v2/oauth/authorize URL + PKCE secrets
 *      → `authorize()` returns an `EveSsoAuthorizeRedirect`.
 *   2. Exchange the authorization `code` for an access token
 *      → `exchangeCode()` returns an `EveSsoToken`.
 *   3. Decode the JWT payload to extract identity claims
 *      → folded into step 2; the token's `characterId` + `characterName`
 *        fields are populated from the JWT.
 *
 * We intentionally DO NOT verify the JWT signature in phase 1. See
 * ADR-0002 § JWT verification — the access token is fetched server-side
 * over TLS from login.eveonline.com, never travels through a
 * user-controlled channel, so the TLS chain is the trust boundary.
 *
 * No framework dependencies beyond `Http` + `Str` — the class is
 * constructor-configurable so tests can swap the config without a
 * full Laravel boot.
 */
final class EveSsoClient
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $callbackUrl,
        private readonly string $authorizeUrl,
        private readonly string $tokenUrl,
    ) {}

    /**
     * Factory: build a client from `config('eve.sso')`.
     *
     * Kept here (not in a service-provider binding) so callers can ask
     * for a client without wiring up the container for one-off use
     * (tinker, artisan commands, tests with env overrides).
     */
    public static function fromConfig(): self
    {
        if (! self::isConfigured()) {
            throw new EveSsoException(
                'EVE SSO is not configured. Set EVE_SSO_CLIENT_ID, EVE_SSO_CLIENT_SECRET, '
                .'and EVE_SSO_CALLBACK_URL in .env (see .env.example § EVE SSO), then '
                .'`php artisan config:clear` if config caching is on.',
            );
        }

        $cfg = config('eve.sso');

        return new self(
            clientId: (string) $cfg['client_id'],
            clientSecret: (string) $cfg['client_secret'],
            callbackUrl: (string) $cfg['callback_url'],
            authorizeUrl: (string) $cfg['authorize_url'],
            tokenUrl: (string) $cfg['token_url'],
        );
    }

    /**
     * Cheap predicate: are the three required env vars populated?
     *
     * Lets call sites (e.g. the Filament login render hook) ask
     * "should I show the EVE button?" without try/catching. Doesn't
     * touch the network — purely a config sanity check.
     */
    public static function isConfigured(): bool
    {
        $cfg = config('eve.sso');

        return ! empty($cfg['client_id'])
            && ! empty($cfg['client_secret'])
            && ! empty($cfg['callback_url']);
    }

    /**
     * Build the /authorize redirect + PKCE secrets.
     *
     * Caller must session-stash `$state` + `$codeVerifier` before
     * redirecting the user; the callback has to present both back.
     *
     * @param string[]|string $scopes  space- or comma-separated list, or array.
     */
    public function authorize(array|string $scopes): EveSsoAuthorizeRedirect
    {
        $scopeList = is_array($scopes)
            ? $scopes
            : preg_split('/[\s,]+/', trim($scopes), -1, PREG_SPLIT_NO_EMPTY);

        $state = Str::random(40);
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->codeChallengeFrom($codeVerifier);

        $url = $this->authorizeUrl.'?'.http_build_query([
            'response_type' => 'code',
            'redirect_uri' => $this->callbackUrl,
            'client_id' => $this->clientId,
            'scope' => implode(' ', $scopeList),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return new EveSsoAuthorizeRedirect($url, $state, $codeVerifier);
    }

    /**
     * Trade an authorization `code` for an access token.
     *
     * Sends the PKCE `code_verifier` and HTTP Basic (`client_id:client_secret`)
     * — EVE SSO's confidential-client + PKCE flow requires both. Returns
     * the full token bundle already decorated with identity claims pulled
     * from the JWT.
     */
    public function exchangeCode(string $code, string $codeVerifier): EveSsoToken
    {
        $response = Http::asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->withHeaders([
                'Host' => 'login.eveonline.com',
            ])
            ->post($this->tokenUrl, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'code_verifier' => $codeVerifier,
            ]);

        if (! $response->successful()) {
            throw new EveSsoException(
                "EVE SSO token exchange failed: HTTP {$response->status()} — {$response->body()}",
            );
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        $accessToken = (string) ($data['access_token'] ?? '');
        if ($accessToken === '') {
            throw new EveSsoException('EVE SSO token exchange returned no access_token');
        }

        $identity = $this->decodeJwtPayload($accessToken);

        return new EveSsoToken(
            accessToken: $accessToken,
            refreshToken: (string) ($data['refresh_token'] ?? ''),
            expiresIn: (int) ($data['expires_in'] ?? 0),
            characterId: $identity['character_id'],
            characterName: $identity['name'],
            scopes: $identity['scopes'],
        );
    }

    /**
     * Unverified JWT payload decode.
     *
     * We DO NOT verify the signature. See class docblock + ADR-0002 for the
     * reasoning. If phase 2 needs verification, add it alongside the refresh
     * flow which needs JWKS anyway.
     *
     * @return array{character_id:int, name:string, scopes:string[]}
     */
    private function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new EveSsoException('Malformed JWT — expected three dot-separated parts');
        }

        $payload = $this->base64UrlDecode($parts[1]);
        $claims = json_decode($payload, true);

        if (! is_array($claims)) {
            throw new EveSsoException('JWT payload is not a JSON object');
        }

        // `sub` is always "CHARACTER:EVE:<id>" for EVE SSO v2.
        $sub = (string) ($claims['sub'] ?? '');
        if (! preg_match('/^CHARACTER:EVE:(\d+)$/', $sub, $m)) {
            throw new EveSsoException("Unexpected JWT sub claim: {$sub}");
        }

        // `scp` can arrive as a string (single scope) or an array (multiple).
        // Normalise to string[].
        $rawScopes = $claims['scp'] ?? [];
        $scopes = match (true) {
            is_array($rawScopes) => array_values(array_map('strval', $rawScopes)),
            is_string($rawScopes) && $rawScopes !== '' => [$rawScopes],
            default => [],
        };

        return [
            'character_id' => (int) $m[1],
            'name' => (string) ($claims['name'] ?? ''),
            'scopes' => $scopes,
        ];
    }

    /**
     * 64-char PKCE verifier from the URL-unreserved alphabet.
     *
     * RFC 7636 § 4.1 requires 43-128 chars from [A-Za-z0-9\-._~].
     * `Str::random()` draws from [A-Za-z0-9] which is a safe subset.
     */
    private function generateCodeVerifier(): string
    {
        return Str::random(64);
    }

    /**
     * `base64url(sha256(verifier))` — RFC 7636 § 4.2.
     */
    private function codeChallengeFrom(string $verifier): string
    {
        return $this->base64UrlEncode(hash('sha256', $verifier, true));
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $encoded): string
    {
        $pad = strlen($encoded) % 4;
        if ($pad > 0) {
            $encoded .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false) {
            throw new EveSsoException('Base64url decode failed');
        }

        return $decoded;
    }
}

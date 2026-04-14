<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\UsersCharacters\Models\EveServiceToken;
use App\Services\Eve\Sso\EveSsoClient;
use Filament\Pages\Page;

/**
 * /admin/eve-service-character — service token authorisation + status view.
 *
 * Phase-2 work landing early. The page exposes one button ("Authorise
 * service character") that kicks off the elevated-scope SSO round-trip
 * (`/auth/eve/service-redirect`), and a status panel showing the current
 * stored token: who it's for, what scopes it grants, when it expires,
 * who clicked the button.
 *
 * Token storage is in `eve_service_tokens` (encrypted at rest via the
 * model's `'encrypted'` cast). Phase-2 work that consumes the stored
 * tokens (Python execution-plane poller, refresh handler) lands in
 * follow-up PRs — this page is the operator surface for "have I
 * granted the app the access it needs to do polling work".
 *
 * See ADR-0002 § Token kinds + the phase-2 amendment for the design
 * trade-offs.
 */
class EveServiceCharacter extends Page
{
    protected string $view = 'filament.pages.eve-service-character';

    protected static ?string $title = 'EVE service character';

    protected static ?string $navigationLabel = 'EVE service character';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    // Sort just after the SDE Status entry (which is at 10).
    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'eve-service-character';

    /**
     * Hide the page from the sidebar when SSO isn't configured at all —
     * an unauth'd nav entry to a page whose only button doesn't work
     * is more confusing than no entry. The page itself stays gated by
     * the panel's auth middleware regardless.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return EveSsoClient::isConfigured();
    }

    /**
     * Hand the view the data it needs without poking the model layer
     * from inside the Blade template. Returning structured data here
     * (rather than the raw model) lets us keep the access/refresh
     * tokens out of the view scope by construction.
     *
     * @return array{
     *   token: array{
     *     character_id: int,
     *     character_name: string,
     *     scopes: array<int, string>,
     *     expires_at: \Carbon\CarbonInterface,
     *     is_fresh: bool,
     *     authorized_by: ?string,
     *     updated_at: \Carbon\CarbonInterface,
     *   }|null,
     *   service_scopes: array<int, string>,
     *   sso_configured: bool,
     *   service_redirect_url: string,
     * }
     */
    protected function getViewData(): array
    {
        // We expect 0 or 1 row — phase-1 model is "one service character
        // per stack". `latest('updated_at')->first()` is harmless if a
        // future change ever stores multiples; the page surfaces the
        // freshest one.
        $token = EveServiceToken::query()
            ->with('authorizedBy:id,name')
            ->latest('updated_at')
            ->first();

        return [
            'token' => $token === null ? null : [
                'character_id' => $token->character_id,
                'character_name' => $token->character_name,
                'scopes' => $token->scopes,
                'expires_at' => $token->expires_at,
                'is_fresh' => $token->isAccessTokenFresh(),
                'authorized_by' => $token->authorizedBy?->name,
                'updated_at' => $token->updated_at,
            ],
            'service_scopes' => $this->configuredServiceScopes(),
            'sso_configured' => EveSsoClient::isConfigured(),
            'service_redirect_url' => route('auth.eve.service.redirect'),
        ];
    }

    /**
     * Normalise EVE_SSO_SERVICE_SCOPES (comma- or space-separated) into a
     * trimmed string[] — matches what `EveSsoClient::authorize()` does.
     *
     * @return array<int, string>
     */
    private function configuredServiceScopes(): array
    {
        $raw = (string) config('eve.sso.service_scopes', '');

        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        )));
    }
}

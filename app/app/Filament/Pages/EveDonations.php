<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\UsersCharacters\Models\EveDonation;
use App\Domains\UsersCharacters\Models\EveDonationsToken;
use App\Services\Eve\Sso\EveSsoClient;
use Filament\Pages\Page;

/**
 * /admin/eve-donations — donations character authorisation + ledger view.
 *
 * Operator surface for the third SSO flow. Three things on one page:
 *
 *   1. Token status panel — who is authorised to run the donations
 *      poller, what scopes they granted, when the access token expires.
 *      Mirrors the EveServiceCharacter page's status pattern but is
 *      locked to a single character ID (env-configured).
 *   2. Authorise CTA — kicks off /auth/eve/donations-redirect when the
 *      donations character is configured. Hidden when no character is
 *      configured (would fail the redirect anyway).
 *   3. Recent donations ledger — the most recent N rows from
 *      eve_donations: who donated, how much ISK, when, and (if filled
 *      in) the in-game reason text.
 *
 * Tokens never enter the view scope — the page hands the Blade template
 * a status snapshot constructed in `getViewData()`, deliberately
 * excluding the encrypted access/refresh columns.
 *
 * See ADR-0002 § phase-2 amendment for the donations flow rationale and
 * App\Domains\UsersCharacters\Jobs\PollDonationsWallet for the polling
 * mechanics.
 */
class EveDonations extends Page
{
    protected string $view = 'filament.pages.eve-donations';

    protected static ?string $title = 'EVE donations';

    protected static ?string $navigationLabel = 'EVE donations';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    // Sort just after the service character entry (which is at 20).
    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'eve-donations';

    /**
     * Hide the page from the sidebar when SSO isn't configured at all
     * OR when the donations character isn't configured. The auth
     * middleware on the route still gates the page itself; this just
     * keeps the nav clean on stacks that aren't using donations.
     */
    public static function shouldRegisterNavigation(): bool
    {
        if (! EveSsoClient::isConfigured()) {
            return false;
        }

        return is_int(config('eve.sso.donations.character_id'));
    }

    /**
     * @return array{
     *   sso_configured: bool,
     *   donations_configured: bool,
     *   expected_character_id: ?int,
     *   expected_character_name: ?string,
     *   donations_redirect_url: string,
     *   token: array{
     *     character_id: int,
     *     character_name: string,
     *     scopes: array<int, string>,
     *     expires_at: \Carbon\CarbonInterface,
     *     is_fresh: bool,
     *     authorized_by: ?string,
     *     updated_at: \Carbon\CarbonInterface,
     *   }|null,
     *   donations: \Illuminate\Support\Collection<int, array{
     *     donor_character_id: int,
     *     donor_name: ?string,
     *     amount: string,
     *     reason: ?string,
     *     donated_at: \Carbon\CarbonInterface,
     *   }>,
     *   donation_total: string,
     *   donor_count: int,
     * }
     */
    protected function getViewData(): array
    {
        $expectedCharacterId = config('eve.sso.donations.character_id');
        $expectedCharacterId = is_int($expectedCharacterId) ? $expectedCharacterId : null;

        $token = EveDonationsToken::query()
            ->with('authorizedBy:id,name')
            ->latest('updated_at')
            ->first();

        // Most-recent donations slice — the page is a status surface,
        // not a paginated ledger. 50 rows comfortably covers a few
        // years of donations for the expected donor base; if the
        // donor base ever grows past that, swap in a Filament Resource
        // with proper pagination (won't be a UX regression, just an
        // upgrade).
        $donations = EveDonation::query()
            ->orderByDesc('donated_at')
            ->limit(50)
            ->get(['donor_character_id', 'donor_name', 'amount', 'reason', 'donated_at'])
            ->map(fn (EveDonation $d): array => [
                'donor_character_id' => $d->donor_character_id,
                'donor_name' => $d->donor_name,
                'amount' => $d->amount,
                'reason' => $d->reason,
                'donated_at' => $d->donated_at,
            ]);

        // Aggregate totals as DB-side sum to avoid float drift on the
        // PHP side. DECIMAL(20, 2) → string stays exact.
        $total = (string) (EveDonation::query()->sum('amount') ?? '0');
        $donorCount = EveDonation::query()
            ->distinct('donor_character_id')
            ->count('donor_character_id');

        return [
            'sso_configured' => EveSsoClient::isConfigured(),
            'donations_configured' => $expectedCharacterId !== null,
            'expected_character_id' => $expectedCharacterId,
            'expected_character_name' => config('eve.sso.donations.character_name'),
            'donations_redirect_url' => route('auth.eve.donations.redirect'),
            'token' => $token === null ? null : [
                'character_id' => $token->character_id,
                'character_name' => $token->character_name,
                'scopes' => $token->scopes,
                'expires_at' => $token->expires_at,
                'is_fresh' => $token->isAccessTokenFresh(),
                'authorized_by' => $token->authorizedBy?->name,
                'updated_at' => $token->updated_at,
            ],
            'donations' => $donations,
            'donation_total' => $total,
            'donor_count' => $donorCount,
        ];
    }
}

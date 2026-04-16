<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Admin dashboard widget: character + user + donor pipeline stats.
 *
 * Shows registered users, linked characters, donor activity, market
 * token coverage, and classification state. All queries hit indexed
 * columns and tolerate empty tables.
 */
class CharacterStatsWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Characters & Users';

    protected ?string $description = 'Identity, donors, and classification.';

    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        $users = (int) DB::table('users')->count();
        $characters = (int) DB::table('characters')->count();
        $linkedChars = (int) DB::table('characters')->whereNotNull('user_id')->count();

        // Donor metrics.
        $uniqueDonors = (int) DB::table('eve_donations')
            ->distinct('donor_character_id')
            ->count('donor_character_id');
        $totalIskDonated = (float) DB::table('eve_donations')->sum('amount');
        $activeDonors = (int) DB::table('eve_donor_benefits')
            ->where('ad_free_until', '>=', now())
            ->count();

        // Market tokens (donors who authorised ESI market access).
        $marketTokens = (int) DB::table('eve_market_tokens')->count();

        // Classification — viewer contexts with confirmed bloc.
        $viewerContexts = (int) DB::table('viewer_contexts')->count();
        $resolvedBlocs = (int) DB::table('viewer_contexts')
            ->whereNotNull('bloc_id')
            ->where('bloc_unresolved', false)
            ->count();

        // Standings coverage.
        $standingsOwners = (int) DB::table('character_standings')
            ->distinct('owner_id')
            ->count('owner_id');

        $stats = [];

        // -- Users + Characters -------------------------------------------

        $charDesc = $characters > 0
            ? "{$linkedChars} linked to accounts"
            : 'No characters yet';

        $stats[] = Stat::make('Users', number_format($users))
            ->description($users === 1 ? '1 account' : "{$users} accounts")
            ->color($users > 0 ? 'primary' : 'gray')
            ->icon('heroicon-o-user-group');

        $stats[] = Stat::make('Characters', number_format($characters))
            ->description($charDesc)
            ->color($characters > 0 ? 'primary' : 'gray')
            ->icon('heroicon-o-identification');

        // -- Donors -------------------------------------------------------

        if ($uniqueDonors > 0) {
            $stats[] = Stat::make('Donors', number_format($uniqueDonors))
                ->description(
                    $activeDonors > 0
                        ? "{$activeDonors} active, ".self::formatIsk($totalIskDonated).' ISK total'
                        : self::formatIsk($totalIskDonated).' ISK total'
                )
                ->color($activeDonors > 0 ? 'success' : 'warning')
                ->icon('heroicon-o-heart');
        } else {
            $stats[] = Stat::make('Donors', '0')
                ->description('No donations received yet')
                ->color('gray')
                ->icon('heroicon-o-heart');
        }

        // -- Market + Classification --------------------------------------

        $stats[] = Stat::make('Market Tokens', number_format($marketTokens))
            ->description($standingsOwners > 0 ? "{$standingsOwners} standings sources" : 'No standings synced')
            ->color($marketTokens > 0 ? 'success' : 'gray')
            ->icon('heroicon-o-chart-bar');

        if ($viewerContexts > 0) {
            $unresolvedCount = $viewerContexts - $resolvedBlocs;
            $stats[] = Stat::make('Classifications', number_format($resolvedBlocs).' / '.number_format($viewerContexts))
                ->description(
                    $unresolvedCount > 0
                        ? "{$unresolvedCount} awaiting bloc confirmation"
                        : 'All viewers have confirmed blocs'
                )
                ->color($unresolvedCount > 0 ? 'warning' : 'success')
                ->icon('heroicon-o-shield-check');
        }

        return $stats;
    }

    private static function formatIsk(float $isk): string
    {
        if ($isk >= 1_000_000_000_000) {
            return number_format($isk / 1_000_000_000_000, 1).'T';
        }
        if ($isk >= 1_000_000_000) {
            return number_format($isk / 1_000_000_000, 1).'B';
        }
        if ($isk >= 1_000_000) {
            return number_format($isk / 1_000_000, 1).'M';
        }

        return number_format($isk, 0);
    }
}

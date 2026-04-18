<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Domains\KillmailsBattleTheaters\Services\BattleFcAttestationRecorder;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * /portal/my-fc-attestations — private view of the current user's
 * own FC attestations (Spec 6 Mode A).
 *
 * Only visible in the portal navigation for donor-tier users, because
 * only donors can create attestations; free-tier users would see an
 * empty list.
 */
class MyFcAttestations extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationLabel = 'My FC attestations';

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?int $navigationSort = 65;

    protected static ?string $title = 'My FC attestations';

    protected string $view = 'filament.portal.pages.my-fc-attestations';

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();
        return $user !== null && method_exists($user, 'isDonor') && $user->isDonor();
    }

    public function getViewData(): array
    {
        $user = Auth::user();
        if ($user === null) {
            return ['rows' => collect(), 'is_donor' => false];
        }
        $recorder = app(BattleFcAttestationRecorder::class);
        return [
            'rows' => $recorder->listForUser($user, 100),
            'is_donor' => method_exists($user, 'isDonor') && $user->isDonor(),
        ];
    }
}

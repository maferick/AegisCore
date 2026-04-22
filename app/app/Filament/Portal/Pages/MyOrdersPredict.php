<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Domains\UsersCharacters\Services\PersonalOrderPredictor;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * /portal/my-orders/predict?station={location_id} — operational
 * recommendations for a single station based on the user's (main +
 * alts) personal sell history at that station plus regional market
 * history. See PersonalOrderPredictor for the signal definitions.
 */
class MyOrdersPredict extends Page
{
    protected static string|BackedEnum|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?string $slug = 'my-orders/predict';

    protected static ?string $title = 'Market Prediction';

    protected string $view = 'filament.portal.pages.my-orders-predict';

    /** @return array<string,mixed> */
    public function getViewData(): array
    {
        $user = Auth::user();
        if ($user === null) return ['no_user' => true];

        $locationId = (int) request()->query('station', 0);
        if ($locationId <= 0) return ['no_station' => true];

        $predictor = app(PersonalOrderPredictor::class);
        $result = $predictor->predict($user, $locationId);

        return ['no_user' => false, 'no_station' => false] + $result;
    }
}

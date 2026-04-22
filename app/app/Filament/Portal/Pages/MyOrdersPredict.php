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

        // Attach main/alt metadata for the raw-listing table.
        $mainCharId = $user->main_character_id;
        $charMeta = [];
        foreach ($user->characters as $c) {
            $charMeta[(int) $c->character_id] = [
                'name' => $c->name,
                'is_main' => $mainCharId !== null && (int) $c->id === (int) $mainCharId,
            ];
        }

        return ['no_user' => false, 'no_station' => false, 'character_meta' => $charMeta] + $result;
    }
}

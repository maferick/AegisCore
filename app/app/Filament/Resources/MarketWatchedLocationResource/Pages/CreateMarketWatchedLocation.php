<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketWatchedLocationResource\Pages;

use App\Filament\Resources\MarketWatchedLocationResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Create page for /admin/market-watched-locations/create.
 *
 * Admin-managed rows only: every row created through this page has
 * `owner_user_id = null`. Donor-owned rows are created from
 * `/account/settings` by the donor themselves and do not flow
 * through here.
 *
 * `consecutive_failure_count` / `last_error` / `last_error_at` /
 * `disabled_reason` default to zero / null at the column level; we
 * don't prompt the admin for them on create. `last_polled_at` stays
 * null until the first successful poll tick.
 */
class CreateMarketWatchedLocation extends CreateRecord
{
    protected static string $resource = MarketWatchedLocationResource::class;

    /**
     * Ensure admin-created rows always land as platform defaults.
     * A donor-owned row coming in through this surface would
     * contradict ADR-0004's "donor self-service" boundary and
     * would confuse the poller's ownership enforcement at fetch
     * time.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['owner_user_id'] = null;
        $data['consecutive_failure_count'] = 0;
        $data['last_error'] = null;
        $data['last_error_at'] = null;
        $data['disabled_reason'] = null;

        return $data;
    }
}

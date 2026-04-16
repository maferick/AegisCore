<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketWatchedLocationResource\Pages;

use App\Domains\Markets\Models\MarketHub;
use App\Filament\Resources\MarketWatchedLocationResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Create page for /admin/market-watched-locations/create.
 *
 * Admin-managed rows only: every row created through this page
 * attaches to a public-reference {@see MarketHub}
 * (`is_public_reference = true`). Private / donor-registered rows
 * are created from `/account/settings` by the donor themselves, and
 * do not flow through here.
 *
 * Before ADR-0005 this page set `owner_user_id = null` on the
 * watched row and had no hub concept. Post-ADR-0005 classification
 * lives on the canonical hub; this page upserts (or reuses) the
 * public-reference hub for the entered `(location_type, location_id)`
 * pair and points the new watched row at it.
 *
 * Edge case: an admin enters an ID that a donor previously
 * registered as a private hub. `firstOrCreate` finds the existing
 * row and reuses it — the admin-create path does NOT silently
 * promote a private hub to public. Classification changes for an
 * existing hub go through the hub surface, not this one (surfaced
 * in the admin UI's classification badge so the mismatch is at
 * least visible).
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
     * Upsert the canonical hub + attach the new watched row to it.
     * Keeps the single-polling-lane invariant ADR-0005 § One polling
     * lane per physical market calls for: a second admin creating
     * the same (location_type, location_id) reuses the hub.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $hub = MarketHub::query()->firstOrCreate(
            [
                'location_type' => $data['location_type'],
                'location_id' => (int) $data['location_id'],
            ],
            [
                'region_id' => (int) $data['region_id'],
                'structure_name' => $data['name'] ?? null,
                'is_public_reference' => true,
                'is_active' => true,
                'created_by_user_id' => null,
            ],
        );

        $data['hub_id'] = $hub->id;
        $data['consecutive_failure_count'] = 0;
        $data['last_error'] = null;
        $data['last_error_at'] = null;
        $data['disabled_reason'] = null;

        return $data;
    }
}

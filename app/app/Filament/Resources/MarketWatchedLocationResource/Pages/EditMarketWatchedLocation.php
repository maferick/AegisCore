<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketWatchedLocationResource\Pages;

use App\Domains\Markets\Models\MarketWatchedLocation;
use App\Filament\Resources\MarketWatchedLocationResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

/**
 * Edit page for /admin/market-watched-locations/{id}/edit.
 *
 * Header actions include a "Reset failure counter" button for the
 * common operator workflow: an upstream ESI hiccup has ticked the
 * consecutive-failure count up to 2/3 (not yet auto-disabled) and
 * the operator wants to avoid the row flipping disabled on the next
 * blip. Resetting zeroes the counter + clears `last_error` without
 * touching enabled state.
 *
 * Delete is hidden for Jita — the resource-level guard + the model's
 * deleting() hook would refuse anyway, but hiding the button avoids
 * an operator clicking it, hitting the error, and wondering if
 * something's broken.
 */
class EditMarketWatchedLocation extends EditRecord
{
    protected static string $resource = MarketWatchedLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetFailureCounter')
                ->label('Reset failure counter')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription(
                    'Zeroes consecutive_failure_count and clears the last error. '
                    .'Does NOT change the `enabled` flag — flip that manually if '
                    .'the row is currently disabled.'
                )
                // Hide when there's nothing to reset.
                ->visible(fn (MarketWatchedLocation $record): bool => $record->consecutive_failure_count > 0 || $record->last_error !== null)
                ->action(function (MarketWatchedLocation $record): void {
                    DB::transaction(function () use ($record): void {
                        $record->update([
                            'consecutive_failure_count' => 0,
                            'last_error' => null,
                            'last_error_at' => null,
                        ]);
                    });
                    $this->refreshFormData(['consecutive_failure_count', 'last_error', 'last_error_at']);
                }),

            DeleteAction::make()
                ->visible(fn (MarketWatchedLocation $record): bool => ! $record->isJita()),
        ];
    }
}

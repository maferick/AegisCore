<?php

declare(strict_types=1);

namespace App\Filament\Resources\EntityClassificationOverrideResource\Pages;

use App\Filament\Resources\EntityClassificationOverrideResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

/**
 * Create page for /admin/entity-classification-overrides/create.
 *
 * Stamps `created_by_character_id` from the admin's primary linked
 * character if available. The column is nullable so an admin user
 * with no EVE-linked character still lands a row; the attribution is
 * just missing in that edge case.
 *
 * The model's saving hook enforces the scope invariant — form-level
 * validation already requires the viewer picker when scope='viewer',
 * so a DomainException here would indicate a form-state bug, not a
 * user input problem.
 */
class CreateEntityClassificationOverride extends CreateRecord
{
    protected static string $resource = EntityClassificationOverrideResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();
        if ($user !== null) {
            $character = $user->characters()->orderBy('id')->first();
            if ($character !== null) {
                $data['created_by_character_id'] = $character->id;
            }
        }

        return $data;
    }
}

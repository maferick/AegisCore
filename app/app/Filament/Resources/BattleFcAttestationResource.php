<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domains\KillmailsBattleTheaters\Models\BattleFcUserAttestation;
use App\Filament\Resources\BattleFcAttestationResource\Pages;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * /admin/fc-attestations — admin-only view of all donor-tier FC
 * attestations (Spec 6 Mode A admin break).
 *
 * Read-only. Admins see every attestation across all users, with
 * battle + alliance + sub-fleet + attested-character context, for:
 *   - monitoring donor engagement with the attestation feature
 *   - surfacing candidate truth-set rows before Spec 7 lands
 *   - spotting potential abuse (e.g. one user flooding attestations)
 *
 * Mode A discipline is preserved: non-admins NEVER reach this page.
 * The PanelProvider routes this under the admin panel only and
 * canViewAny defaults to the User::isAdmin() gate via canAccessPanel.
 *
 * No create/edit/delete affordances. Attestations are authored
 * exclusively from the portal "Mark FC" control; admin surface is
 * audit-only.
 */
class BattleFcAttestationResource extends Resource
{
    protected static ?string $model = BattleFcUserAttestation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static string|UnitEnum|null $navigationGroup = 'Classification';

    protected static ?string $navigationLabel = 'FC attestations';

    protected static ?string $modelLabel = 'FC attestation';

    protected static ?string $pluralModelLabel = 'FC attestations';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'fc-attestations';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $q) => $q
                    ->leftJoin('users', 'users.id', '=', 'battle_fc_user_attestations.user_id')
                    ->leftJoin('battle_theaters', 'battle_theaters.id', '=', 'battle_fc_user_attestations.battle_id')
                    ->leftJoin('esi_entity_names AS en_char', function ($j) {
                        $j->on('en_char.entity_id', '=', 'battle_fc_user_attestations.attested_character_id')
                          ->where('en_char.category', '=', 'character');
                    })
                    ->leftJoin('esi_entity_names AS en_ally', function ($j) {
                        $j->on('en_ally.entity_id', '=', 'battle_fc_user_attestations.alliance_id')
                          ->where('en_ally.category', '=', 'alliance');
                    })
                    ->select(
                        'battle_fc_user_attestations.*',
                        'users.name AS user_name',
                        'users.email AS user_email',
                        'battle_theaters.public_slug AS battle_slug',
                        'en_char.name AS attested_character_name',
                        'en_ally.name AS alliance_name',
                    )
            )
            ->defaultSort('attested_at', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('attested_at')
                    ->label('When')
                    ->dateTime('Y-m-d H:i:s')
                    ->description(fn ($record) => \Carbon\Carbon::parse($record->attested_at)->diffForHumans())
                    ->sortable(),

                TextColumn::make('user_name')
                    ->label('User')
                    ->description(fn ($record) => $record->user_email)
                    ->searchable(query: fn (Builder $q, string $s) => $q->where('users.name', 'like', "%{$s}%")->orWhere('users.email', 'like', "%{$s}%"))
                    ->sortable(),

                TextColumn::make('battle_slug')
                    ->label('Battle')
                    ->url(fn ($record) => $record->battle_slug ? '/portal/battles/' . $record->battle_id : null)
                    ->placeholder(fn ($record) => '#' . $record->battle_id)
                    ->searchable(query: fn (Builder $q, string $s) => $q->where('battle_theaters.public_slug', 'like', "%{$s}%")),

                TextColumn::make('alliance_name')
                    ->label('Alliance')
                    ->placeholder(fn ($record) => 'Alliance #' . $record->alliance_id)
                    ->toggleable(),

                TextColumn::make('sub_fleet_id')
                    ->label('SF')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('partition_algo_version')
                    ->label('v')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('attested_character_name')
                    ->label('Attested FC')
                    ->placeholder(fn ($record) => 'char_' . $record->attested_character_id)
                    ->searchable(query: fn (Builder $q, string $s) => $q->where('en_char.name', 'like', "%{$s}%")),

                TextColumn::make('user_note')
                    ->label('Note')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->user_note)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('sub_fleet_id')
                    ->options(fn () => BattleFcUserAttestation::query()
                        ->distinct()
                        ->orderBy('sub_fleet_id')
                        ->pluck('sub_fleet_id', 'sub_fleet_id')
                        ->map(fn ($v) => "SF {$v}")
                        ->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBattleFcAttestations::route('/'),
        ];
    }
}

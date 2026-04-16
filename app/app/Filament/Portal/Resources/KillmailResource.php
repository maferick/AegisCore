<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources;

use App\Domains\KillmailsBattleTheaters\Models\Killmail;
use App\Filament\Portal\Resources\KillmailResource\Pages;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Killmails involving the logged-in user's character — as victim or
 * attacker. Browse-only (no create/edit/delete).
 */
class KillmailResource extends Resource
{
    protected static ?string $model = Killmail::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static string|UnitEnum|null $navigationGroup = 'Combat';

    protected static ?string $navigationLabel = 'My Killmails';

    protected static ?string $modelLabel = 'killmail';

    protected static ?string $pluralModelLabel = 'killmails';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'killmails';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('killed_at', 'desc')
            ->modifyQueryUsing(function (Builder $query) {
                $user = auth()->user();
                $character = $user?->characters()->first();
                if (! $character) {
                    return $query->whereRaw('1 = 0');
                }
                $charId = $character->character_id;

                // Killmails where the user is either victim or attacker.
                return $query->where(function (Builder $q) use ($charId) {
                    $q->where('victim_character_id', $charId)
                      ->orWhereIn('killmail_id', function ($sub) use ($charId) {
                          $sub->select('killmail_id')
                              ->from('killmail_attackers')
                              ->where('character_id', $charId);
                      });
                });
            })
            ->columns([
                TextColumn::make('killed_at')
                    ->label('Date')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                TextColumn::make('victim_ship_type_name')
                    ->label('Ship')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('victim_ship_group_name')
                    ->label('Class')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->state(function (Killmail $record): string {
                        $user = auth()->user();
                        $character = $user?->characters()->first();
                        if ($character && $record->victim_character_id === $character->character_id) {
                            return 'Victim';
                        }

                        return 'Attacker';
                    })
                    ->color(fn (string $state): string => $state === 'Victim' ? 'danger' : 'success'),

                TextColumn::make('total_value')
                    ->label('Value')
                    ->numeric(decimalPlaces: 0)
                    ->suffix(' ISK')
                    ->sortable(),

                TextColumn::make('attacker_count')
                    ->label('Attackers')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('region_id')
                    ->label('Region')
                    ->state(function (Killmail $record): string {
                        return $record->region?->name ?? (string) $record->region_id;
                    })
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options([
                        'victim' => 'Deaths',
                        'attacker' => 'Kills',
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (empty($data['value'])) {
                            return;
                        }

                        $user = auth()->user();
                        $character = $user?->characters()->first();
                        if (! $character) {
                            return;
                        }
                        $charId = $character->character_id;

                        if ($data['value'] === 'victim') {
                            $query->where('victim_character_id', $charId);
                        } else {
                            $query->whereIn('killmail_id', function ($sub) use ($charId) {
                                $sub->select('killmail_id')
                                    ->from('killmail_attackers')
                                    ->where('character_id', $charId);
                            });
                        }
                    }),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        // Browse-only — no form needed.
        return $schema->components([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKillmails::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}

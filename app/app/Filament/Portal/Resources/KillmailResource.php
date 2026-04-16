<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources;

use App\Domains\KillmailsBattleTheaters\Models\Killmail;
use App\Filament\Portal\Resources\KillmailResource\Pages;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

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
            ->recordUrl(fn (Killmail $record): string => static::getUrl('view', ['record' => $record->killmail_id]))
            ->modifyQueryUsing(function (Builder $query) {
                $user = auth()->user();
                $character = $user?->characters()->first();
                if (! $character) {
                    return $query->whereRaw('1 = 0');
                }
                $charId = $character->character_id;

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
                ImageColumn::make('ship_render')
                    ->label('')
                    ->state(fn (Killmail $record): string => "https://images.evetech.net/types/{$record->victim_ship_type_id}/render?size=64")
                    ->size(40)
                    ->circular(false),

                TextColumn::make('victim_ship_type_name')
                    ->label('Ship')
                    ->placeholder('—')
                    ->searchable()
                    ->description(fn (Killmail $record): string => $record->victim_ship_group_name ?? ''),

                TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->state(function (Killmail $record): string {
                        $user = auth()->user();
                        $character = $user?->characters()->first();
                        if ($character && $record->victim_character_id === $character->character_id) {
                            return 'Loss';
                        }

                        return 'Kill';
                    })
                    ->color(fn (string $state): string => $state === 'Loss' ? 'danger' : 'success'),

                // Victim: portrait + name + corp / alliance.
                ImageColumn::make('victim_portrait')
                    ->label('')
                    ->state(fn (Killmail $record): ?string => $record->victim_character_id
                        ? "https://images.evetech.net/characters/{$record->victim_character_id}/portrait?size=64"
                        : null)
                    ->size(32)
                    ->circular()
                    ->defaultImageUrl('https://images.evetech.net/types/670/icon?size=64'),

                TextColumn::make('victim_info')
                    ->label('Victim')
                    ->state(function (Killmail $record): string {
                        if (! $record->victim_character_id) {
                            return $record->victim_ship_type_name ?? 'Structure';
                        }

                        return DB::table('esi_entity_names')
                            ->where('entity_id', $record->victim_character_id)
                            ->value('name') ?? 'Pilot #'.$record->victim_character_id;
                    })
                    ->description(function (Killmail $record): string {
                        $parts = [];
                        if ($record->victim_corporation_id) {
                            $parts[] = DB::table('esi_entity_names')
                                ->where('entity_id', $record->victim_corporation_id)
                                ->value('name') ?? '';
                        }
                        if ($record->victim_alliance_id) {
                            $alliance = DB::table('esi_entity_names')
                                ->where('entity_id', $record->victim_alliance_id)
                                ->value('name');
                            if ($alliance) {
                                $parts[] = $alliance;
                            }
                        }

                        return implode(' / ', array_filter($parts));
                    }),

                // Final blow: portrait + name + ship.
                TextColumn::make('final_blow')
                    ->label('Final Blow')
                    ->state(function (Killmail $record): string {
                        $fb = DB::table('killmail_attackers')
                            ->where('killmail_id', $record->killmail_id)
                            ->where('is_final_blow', true)
                            ->first(['character_id', 'corporation_id', 'ship_type_id']);

                        if (! $fb) {
                            return '—';
                        }

                        if ($fb->character_id) {
                            $name = DB::table('esi_entity_names')
                                ->where('entity_id', $fb->character_id)
                                ->value('name') ?? 'Pilot #'.$fb->character_id;
                        } else {
                            $name = 'NPC';
                        }

                        return $name;
                    })
                    ->description(function (Killmail $record): string {
                        $fb = DB::table('killmail_attackers')
                            ->where('killmail_id', $record->killmail_id)
                            ->where('is_final_blow', true)
                            ->first(['ship_type_id', 'corporation_id']);

                        if (! $fb) {
                            return '';
                        }

                        $parts = [];
                        if ($fb->ship_type_id) {
                            $parts[] = DB::table('ref_item_types')
                                ->where('id', $fb->ship_type_id)
                                ->value('name') ?? '';
                        }
                        if ($fb->corporation_id) {
                            $corp = DB::table('esi_entity_names')
                                ->where('entity_id', $fb->corporation_id)
                                ->value('name');
                            if ($corp) {
                                $parts[] = $corp;
                            }
                        }

                        return implode(' · ', array_filter($parts));
                    }),

                TextColumn::make('total_value')
                    ->label('Value')
                    ->formatStateUsing(function ($state): string {
                        $v = (float) $state;
                        if ($v >= 1_000_000_000) {
                            return number_format($v / 1_000_000_000, 1).'B';
                        }
                        if ($v >= 1_000_000) {
                            return number_format($v / 1_000_000, 1).'M';
                        }
                        if ($v >= 1_000) {
                            return number_format($v / 1_000, 1).'K';
                        }

                        return number_format($v, 0);
                    })
                    ->sortable()
                    ->color('warning'),

                TextColumn::make('attacker_count')
                    ->label('Pilots')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('killed_at')
                    ->label('Date')
                    ->dateTime('M d, H:i')
                    ->sortable()
                    ->color('gray'),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options([
                        'victim' => 'Losses',
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
        return $schema->components([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKillmails::route('/'),
            'view' => Pages\ViewKillmail::route('/{record}'),
        ];
    }

    public static function getRecordRouteKeyName(): ?string
    {
        return 'killmail_id';
    }

    public static function canCreate(): bool
    {
        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources;

use App\Domains\KillmailsBattleTheaters\Models\Killmail;
use App\Filament\Portal\Resources\KillmailBrowserResource\Pages;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Browse all killmails — search by system, region, ship, value.
 * Available to all authenticated portal users.
 */
class KillmailBrowserResource extends Resource
{
    protected static ?string $model = Killmail::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static string|UnitEnum|null $navigationGroup = 'Intel';

    protected static ?string $navigationLabel = 'Kill Browser';

    protected static ?string $modelLabel = 'killmail';

    protected static ?string $pluralModelLabel = 'killmails';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'browse';

    public static function table(Table $table): Table
    {
        return $table
            // Default query hides:
            //   1. Unenriched killmails — skeleton rows the stream
            //      writes before EnrichPendingKillmails fills the
            //      victim / ship / value fields in.
            //   2. Tombstoned rows — enriched_at is set but every
            //      field is null/0. Those are kills the enrichment
            //      pipeline gave up on (ESI 404, malformed payload,
            //      dead killmail ID). They have ``victim_ship_type_id
            //      = 0`` and would render as "Structure / 0 ISK / 0
            //      pilots" placeholders on the browser.
            //
            // Filtering on ``victim_ship_type_id > 0`` catches both
            // cases in one predicate: unenriched rows were inserted
            // with ``victim_ship_type_id = 0`` too, and a real kill
            // always has a non-zero ship type id.
            //
            // "Include unenriched (raw stream rows)" filter below
            // strips this predicate for operators debugging ingest.
            ->modifyQueryUsing(fn (Builder $query) => $query->where('victim_ship_type_id', '>', 0))
            ->defaultSort('killed_at', 'desc')
            ->recordUrl(fn (Killmail $record): string => KillmailResource::getUrl('view', ['record' => $record->killmail_id]))
            ->columns([
                ImageColumn::make('ship_render')
                    ->label('')
                    ->state(fn (Killmail $record): string => "https://images.evetech.net/types/{$record->victim_ship_type_id}/render?size=64")
                    ->size(40)
                    ->circular(false),

                TextColumn::make('ship_display')
                    ->label('Ship')
                    ->state(function (Killmail $record): string {
                        return $record->victim_ship_type_name
                            ?? DB::table('ref_item_types')->where('id', $record->victim_ship_type_id)->value('name')
                            ?? '—';
                    })
                    ->description(fn (Killmail $record): string => $record->victim_ship_group_name
                        ?? DB::table('ref_item_types as t')
                            ->join('ref_item_groups as g', 'g.id', '=', 't.group_id')
                            ->where('t.id', $record->victim_ship_type_id)
                            ->value('g.name') ?? '')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('victim_ship_type_name', 'like', "%{$search}%");
                    }),

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

                TextColumn::make('total_value')
                    ->label('Value')
                    ->formatStateUsing(function ($state): string {
                        $v = (float) $state;
                        if ($v >= 1e9) {
                            return number_format($v / 1e9, 1).'B';
                        }
                        if ($v >= 1e6) {
                            return number_format($v / 1e6, 1).'M';
                        }
                        if ($v >= 1e3) {
                            return number_format($v / 1e3, 1).'K';
                        }

                        return number_format($v, 0);
                    })
                    ->sortable()
                    ->color('warning'),

                TextColumn::make('attacker_count')
                    ->label('Pilots')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('region_name')
                    ->label('Region')
                    ->state(fn (Killmail $record): string => $record->region?->name ?? '')
                    ->toggleable(),

                TextColumn::make('killed_at')
                    ->label('Date')
                    ->dateTime('M d, H:i')
                    ->sortable()
                    ->color('gray'),
            ])
            ->filters([
                Filter::make('high_value')
                    ->label('High Value (>1B)')
                    ->query(fn (Builder $query) => $query->where('total_value', '>=', 1_000_000_000)),

                Filter::make('solo')
                    ->label('Solo Kills')
                    ->query(fn (Builder $query) => $query->where('is_solo_kill', true)),

                Filter::make('include_unenriched')
                    ->label('Include unenriched (raw stream rows)')
                    ->query(function (Builder $query): Builder {
                        // ``modifyQueryUsing`` added the
                        // ``victim_ship_type_id > 0`` predicate up
                        // front; when this filter is active we want to
                        // drop it so operators can inspect skeleton /
                        // tombstoned rows. No public Filament API
                        // subtracts a prior scope, but clearing the
                        // bound clause on the underlying Builder is
                        // safe — nothing else on the browser query
                        // touches that column.
                        $query->getQuery()->wheres = array_values(array_filter(
                            $query->getQuery()->wheres,
                            fn (array $w): bool => ($w['column'] ?? null) !== 'victim_ship_type_id',
                        ));
                        return $query;
                    }),

                SelectFilter::make('victim_ship_category')
                    ->label('Ship Category')
                    ->options([
                        'Ship' => 'Ships',
                        'Structure' => 'Structures',
                        'Deployable' => 'Deployables',
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (! empty($data['value'])) {
                            $query->where('victim_ship_category_name', $data['value']);
                        }
                    }),

                Filter::make('region')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('region_name')
                            ->label('Region name')
                            ->placeholder('e.g. Delve, Pure Blind...'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (! empty($data['region_name'])) {
                            $regionIds = DB::table('ref_regions')
                                ->where('name', 'like', '%'.$data['region_name'].'%')
                                ->pluck('id');
                            if ($regionIds->isNotEmpty()) {
                                $query->whereIn('region_id', $regionIds);
                            }
                        }
                    }),
            ])
            ->defaultPaginationPageOption(25);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKillmailBrowser::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}

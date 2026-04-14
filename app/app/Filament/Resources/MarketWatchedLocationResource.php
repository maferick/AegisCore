<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domains\Markets\Models\MarketWatchedLocation;
use App\Filament\Resources\MarketWatchedLocationResource\Pages;
use App\Reference\Models\NpcStation;
use App\Reference\Models\Region;
use App\Reference\Models\SolarSystem;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * /admin/market-watched-locations — admin surface for the market poller's driver table.
 *
 * ADR-0004 § Filament / frontend split. This resource covers the
 * **platform-default** (admin-managed, `owner_user_id = NULL`) slice
 * of `market_watched_locations`. Donor-owned rows appear read-only in
 * the list (operators may want to spot-check activity) but their
 * create/edit flow lives at `/account/settings`, not here.
 *
 * Phase 4b scope:
 *
 *   - Browse + filter all rows (platform + donor-owned).
 *   - Create a new NPC station row (picker backed by `ref_npc_stations`).
 *   - Create a new player_structure row by pasting the ID. The
 *     structure picker backed by ESI-search lands in a later step;
 *     the poller already fails closed on 403 so pasting a
 *     not-accessible ID is self-correcting (consecutive_failure_count
 *     → auto-disable).
 *   - Edit: toggle `enabled`, override display `name`, view failure
 *     bookkeeping (read-only).
 *   - Delete: Jita 4-4 is protected both here (resource-level
 *     `canDelete`) and in the model's `booted()` hook — belt-and-braces.
 *
 * All edits are lightweight — Laravel writes only to MariaDB. The
 * Python poller picks up the change on its next tick; no outbox event
 * fires from the admin surface.
 */
class MarketWatchedLocationResource extends Resource
{
    protected static ?string $model = MarketWatchedLocation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|UnitEnum|null $navigationGroup = 'Markets';

    protected static ?string $navigationLabel = 'Watched locations';

    protected static ?string $modelLabel = 'watched location';

    protected static ?string $pluralModelLabel = 'watched locations';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'market-watched-locations';

    /**
     * The resource form — drives both Create and Edit pages. We keep
     * the two flows in one schema and branch on the selected
     * `location_type` so adding a new location type later (e.g. a
     * third-party price feed) only needs a new branch, not a new
     * page.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Location')
                ->description(
                    'Where to poll. NPC stations resolve against the SDE '
                    .'reference tables; player structures require an explicit '
                    .'structure ID and access via the authorised service '
                    .'character.'
                )
                ->schema([
                    Select::make('location_type')
                        ->label('Kind')
                        ->options([
                            MarketWatchedLocation::LOCATION_TYPE_NPC_STATION => 'NPC station',
                            MarketWatchedLocation::LOCATION_TYPE_PLAYER_STRUCTURE => 'Player structure',
                        ])
                        ->required()
                        ->native(false)
                        ->live()
                        // Editing a row's location_type after creation
                        // would invalidate its source-string and
                        // confuse historical observation provenance.
                        // Force a recreate if the kind genuinely
                        // changes.
                        ->disabledOn('edit'),

                    // NPC-station-only: pick from ref_npc_stations. The
                    // select is searchable because there are ~4 500 NPC
                    // stations across New Eden; an unpaginated dropdown
                    // would be unusable. `getSearchResultsUsing` does a
                    // LIKE over the JSON `data` blob's operation name
                    // — there's no materialised display name on
                    // ref_npc_stations (CCP composes it at runtime), so
                    // we fall back to "system • station_id" for the
                    // visible label.
                    Select::make('location_id')
                        ->label('NPC station')
                        ->required()
                        ->searchable()
                        ->preload(false)
                        ->native(false)
                        ->visible(fn ($get) => $get('location_type') === MarketWatchedLocation::LOCATION_TYPE_NPC_STATION)
                        ->disabledOn('edit')
                        ->getSearchResultsUsing(fn (string $search): array => self::searchNpcStations($search))
                        ->getOptionLabelUsing(fn ($value) => self::labelForNpcStation((int) $value))
                        ->helperText(
                            'Pick by station name (partial match) or paste a station ID. '
                            .'Region is derived from the station row automatically.'
                        )
                        ->afterStateUpdated(function ($state, $set) {
                            if (! $state) {
                                return;
                            }
                            $station = NpcStation::find((int) $state);
                            if ($station === null) {
                                return;
                            }
                            $system = SolarSystem::find((int) $station->solar_system_id);
                            if ($system !== null) {
                                $set('region_id', (int) $system->region_id);
                            }
                            // Prefill name cache with a readable label.
                            $set('name', self::labelForNpcStation((int) $station->id));
                        }),

                    // Player-structure path. The admin knows the
                    // structure ID (from zKill, from in-game, from the
                    // structure owner) and types it in. First poll
                    // validates access; a 403 auto-disables the row
                    // with `disabled_reason = no_access` after the
                    // consecutive-failure threshold. Region_id is
                    // required and entered separately because
                    // /universe/structures/{id}/ resolution hasn't
                    // landed in the admin surface yet (future step).
                    Group::make([
                        TextInput::make('location_id')
                            ->label('Structure ID')
                            ->required()
                            ->numeric()
                            ->minValue(1_000_000_000_000)
                            ->maxValue(9_999_999_999_999)
                            ->disabledOn('edit')
                            ->helperText(
                                'Upwell structure ID (13-digit range). The service '
                                .'character must have docking access or the poller '
                                .'will auto-disable after the configured 403 '
                                .'threshold.'
                            ),
                        TextInput::make('region_id')
                            ->label('Region ID')
                            ->required()
                            ->numeric()
                            ->minValue(10_000_000)
                            ->maxValue(14_000_000)
                            ->disabledOn('edit')
                            ->helperText('Numeric region ID (e.g. 10000002 for The Forge).'),
                    ])
                        ->visible(fn ($get) => $get('location_type') === MarketWatchedLocation::LOCATION_TYPE_PLAYER_STRUCTURE)
                        ->columns(2),

                    // Hidden region_id for NPC rows — populated by the
                    // NPC picker's afterStateUpdated hook above. Kept
                    // as a regular field (disabled) so the form-fill
                    // round-trip sees it on edit.
                    TextInput::make('region_id')
                        ->label('Region ID')
                        ->numeric()
                        ->disabled()
                        ->dehydrated()
                        ->visible(fn ($get) => $get('location_type') === MarketWatchedLocation::LOCATION_TYPE_NPC_STATION),

                    TextInput::make('name')
                        ->label('Display name')
                        ->maxLength(200)
                        ->helperText(
                            'Human-readable label shown in the list and in poller '
                            .'logs. NPC stations get auto-prefilled; player structures '
                            .'start blank until the first successful poll resolves '
                            .'them via /universe/structures/{id}/.'
                        ),
                ])
                ->columns(1),

            Section::make('Polling state')
                ->description(
                    'Operator controls + read-only telemetry. The Python poller '
                    .'writes the failure columns; the admin writes `enabled`.'
                )
                ->schema([
                    Toggle::make('enabled')
                        ->label('Enabled')
                        ->helperText(
                            'Off = poller skips this row. Auto-flipped off by the '
                            .'poller when the consecutive-failure threshold trips.'
                        )
                        ->default(true),

                    Placeholder::make('last_polled_at_display')
                        ->label('Last polled at')
                        ->content(fn (?MarketWatchedLocation $record): string => $record?->last_polled_at?->diffForHumans() ?? 'never'),

                    Placeholder::make('consecutive_failure_count_display')
                        ->label('Consecutive failures')
                        ->content(fn (?MarketWatchedLocation $record): string => (string) ($record?->consecutive_failure_count ?? 0)),

                    Placeholder::make('disabled_reason_display')
                        ->label('Disabled reason')
                        ->content(fn (?MarketWatchedLocation $record): string => $record?->disabled_reason ?? '—'),

                    Placeholder::make('last_error_display')
                        ->label('Last error')
                        ->content(fn (?MarketWatchedLocation $record): string => $record?->last_error ?? '—'),
                ])
                ->visibleOn('edit')
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_polled_at', 'asc')
            ->columns([
                TextColumn::make('location_type')
                    ->label('Kind')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        MarketWatchedLocation::LOCATION_TYPE_NPC_STATION => 'NPC',
                        MarketWatchedLocation::LOCATION_TYPE_PLAYER_STRUCTURE => 'Structure',
                        default => $state,
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === MarketWatchedLocation::LOCATION_TYPE_NPC_STATION
                        ? 'gray'
                        : 'primary'),

                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->wrap()
                    ->placeholder('(unresolved)'),

                TextColumn::make('region.name')
                    ->label('Region')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('location_id')
                    ->label('Location ID')
                    ->copyable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('owner_user_id')
                    ->label('Owner')
                    ->formatStateUsing(fn ($state, MarketWatchedLocation $record): string => $record->owner?->name
                        ?? ($state === null ? 'Platform' : '#'.$state)
                    )
                    ->badge()
                    ->color(fn (MarketWatchedLocation $record): string => $record->owner_user_id === null ? 'success' : 'warning'),

                IconColumn::make('enabled')
                    ->label('On')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('last_polled_at')
                    ->label('Last polled')
                    ->since()
                    ->placeholder('never')
                    ->sortable(),

                TextColumn::make('consecutive_failure_count')
                    ->label('Fails')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state === 0 => 'gray',
                        $state < 3 => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('disabled_reason')
                    ->label('Disabled reason')
                    ->toggleable()
                    ->placeholder('—')
                    ->badge()
                    ->color('danger'),
            ])
            ->filters([
                SelectFilter::make('location_type')
                    ->options([
                        MarketWatchedLocation::LOCATION_TYPE_NPC_STATION => 'NPC stations',
                        MarketWatchedLocation::LOCATION_TYPE_PLAYER_STRUCTURE => 'Player structures',
                    ]),
                TernaryFilter::make('enabled')
                    ->label('Enabled')
                    ->placeholder('All')
                    ->trueLabel('Enabled only')
                    ->falseLabel('Disabled only'),
                TernaryFilter::make('owner')
                    ->label('Owner')
                    ->placeholder('All')
                    ->trueLabel('Platform')
                    ->falseLabel('Donor-owned')
                    ->queries(
                        true: fn ($query) => $query->whereNull('owner_user_id'),
                        false: fn ($query) => $query->whereNotNull('owner_user_id'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    // Belt (Filament-level guard); braces lives on the
                    // model's deleting() hook.
                    ->visible(fn (MarketWatchedLocation $record): bool => ! $record->isJita()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->action(function ($records): void {
                            foreach ($records as $record) {
                                if (! $record->isJita()) {
                                    $record->delete();
                                }
                            }
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketWatchedLocations::route('/'),
            'create' => Pages\CreateMarketWatchedLocation::route('/create'),
            'edit' => Pages\EditMarketWatchedLocation::route('/{record}/edit'),
        ];
    }

    // -- helpers ----------------------------------------------------------

    /**
     * Searchable picker over `ref_npc_stations`. NPC stations don't
     * have a persistent display name column (CCP composes the label
     * at runtime from operation + orbit body), so we compose one by
     * joining through `ref_solar_systems` for the system name and
     * showing the station ID as a disambiguation suffix.
     *
     * @return array<int, string>
     */
    private static function searchNpcStations(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        $q = NpcStation::query()->limit(50);

        if (ctype_digit($search)) {
            $q->where('id', '=', (int) $search);
        } else {
            // Partial-match against the JSON `data.operationNameID`
            // won't work without a JSON path cast — fall back to
            // system-name search, which is what operators actually
            // remember ("Jita", "Amarr", "Perimeter").
            $systemIds = SolarSystem::query()
                ->where('name', 'like', '%'.$search.'%')
                ->pluck('id');
            $q->whereIn('solar_system_id', $systemIds);
        }

        $stations = $q->get(['id', 'solar_system_id']);

        $systemIds = $stations->pluck('solar_system_id')->unique()->all();
        $systemNames = SolarSystem::query()
            ->whereIn('id', $systemIds)
            ->pluck('name', 'id');

        $options = [];
        foreach ($stations as $station) {
            $options[(int) $station->id] = sprintf(
                '%s • station %d',
                $systemNames[$station->solar_system_id] ?? 'unknown system',
                $station->id,
            );
        }

        return $options;
    }

    /** Label for a known NPC station ID — used by form edit pre-fill. */
    private static function labelForNpcStation(int $stationId): string
    {
        $station = NpcStation::find($stationId);
        if ($station === null) {
            return (string) $stationId;
        }
        $system = SolarSystem::find((int) $station->solar_system_id);
        $systemName = $system?->name ?? 'unknown system';

        return sprintf('%s • station %d', $systemName, $stationId);
    }
}

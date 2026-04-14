<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domains\Markets\Models\MarketWatchedLocation;
use App\Domains\Markets\Services\StructurePickerService;
use App\Domains\UsersCharacters\Models\EveServiceToken;
use App\Filament\Resources\MarketWatchedLocationResource\Pages;
use App\Reference\Models\NpcStation;
use App\Reference\Models\Region;
use App\Reference\Models\SolarSystem;
use App\Services\Eve\ServiceTokenAuthorizer;
use BackedEnum;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
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

                    // Player-structure path — searchable picker over
                    // ESI `/characters/{id}/search/?categories=structure`
                    // using the platform service character's token. The
                    // admin types a name fragment (system prefix like
                    // "4-HWWF" or a structure-name fragment) and the
                    // dropdown shows matching Upwell structures the
                    // service character has docking rights at.
                    //
                    // No free-form paste fallback: discovery is
                    // ACL-gated and structure IDs outside the service
                    // character's access set would 403 on first poll
                    // and auto-disable. Making the admin search via
                    // the token surfaces that fact up-front, at
                    // picker time, instead of after a failure sweep.
                    //
                    // Region is derived automatically from the resolved
                    // structure's solar_system_id → ref_solar_systems
                    // join (the picker returns it pre-resolved).
                    Select::make('location_id')
                        ->label('Player structure')
                        ->required()
                        ->searchable()
                        ->preload(false)
                        ->native(false)
                        ->visible(fn ($get) => $get('location_type') === MarketWatchedLocation::LOCATION_TYPE_PLAYER_STRUCTURE)
                        ->disabledOn('edit')
                        ->helperText(
                            'Type a system name (e.g. "4-HWWF") or structure '
                            .'name fragment. Matches are gated by the service '
                            .'character\'s docking rights — a structure you '
                            .'don\'t see here is one the platform can\'t poll.'
                        )
                        ->getSearchResultsUsing(fn (string $search): array => self::searchPlayerStructures($search))
                        ->getOptionLabelUsing(fn ($value) => self::labelForPlayerStructure((int) $value))
                        ->afterStateUpdated(function ($state, $set): void {
                            if (! $state) {
                                return;
                            }
                            $resolved = self::cachedStructureResolution((int) $state);
                            if ($resolved === null) {
                                return;
                            }
                            $set('region_id', $resolved['region_id']);
                            $set('name', $resolved['name']);
                        }),

                    // Read-only region mirror for the structure path.
                    // Populated by afterStateUpdated above; shown so
                    // the admin can eyeball that the resolved region
                    // matches expectations before submitting.
                    TextInput::make('region_id')
                        ->label('Region ID')
                        ->numeric()
                        ->disabled()
                        ->dehydrated()
                        ->visible(fn ($get) => $get('location_type') === MarketWatchedLocation::LOCATION_TYPE_PLAYER_STRUCTURE)
                        ->helperText('Auto-filled from the selected structure\'s solar system.'),

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

    /**
     * Searchable picker over Upwell player structures, via ESI
     * `/characters/{id}/search/?categories=structure` using the
     * platform service character's token.
     *
     * The search is ACL-gated on CCP's side: ESI only returns IDs the
     * service character has docking rights at. That means a
     * structure we "can't see" here is genuinely one the platform
     * cannot poll — surfacing that at picker time (empty results)
     * is better UX than accepting any 13-digit number and
     * auto-disabling it on the first poll sweep.
     *
     * The search string commonly matches the system-name prefix
     * (e.g. "4-HWWF" → "4-HWWF - GSF Keepstar") because coalition
     * staging structures embed their system name in their display
     * name. Structure-name fragments like "Keepstar" or
     * "Fortizar" also work.
     *
     * Each resolved candidate is cached in Laravel's array cache
     * for the request lifetime (keyed by structure_id) so the
     * picker's afterStateUpdated hook can look up `region_id` +
     * `name` without re-resolving against ESI.
     *
     * Returns an array keyed by structure_id with composed labels:
     *
     *     [
     *       1035466617946 => 'Perimeter - Tranquility Trading Tower (Perimeter, The Forge)',
     *       ...
     *     ]
     *
     * @return array<int, string>
     */
    private static function searchPlayerStructures(string $search): array
    {
        $search = trim($search);
        if (strlen($search) < 3) {
            return [];
        }

        $serviceToken = EveServiceToken::query()->orderByDesc('id')->first();
        if ($serviceToken === null) {
            self::notifyPickerError(
                'No service character authorised',
                'Authorise a service character under /admin before adding player structures.'
            );

            return [];
        }

        try {
            /** @var ServiceTokenAuthorizer $authorizer */
            $authorizer = app(ServiceTokenAuthorizer::class);
            /** @var StructurePickerService $picker */
            $picker = app(StructurePickerService::class);

            $accessToken = $authorizer->freshAccessToken($serviceToken);
            $results = $picker->search(
                characterId: (int) $serviceToken->character_id,
                accessToken: $accessToken,
                query: $search,
            );
        } catch (RuntimeException $e) {
            // Surfaced to the admin as a Filament toast so they know
            // the search failed vs. just "no matches".
            self::notifyPickerError('Structure search failed', $e->getMessage());

            return [];
        } catch (Throwable $e) {
            Log::error('admin structure picker: unexpected exception', [
                'error' => $e->getMessage(),
            ]);
            self::notifyPickerError('Structure search failed', 'Unexpected error — check laravel.log.');

            return [];
        }

        $options = [];
        foreach ($results as $r) {
            $id = (int) $r['structure_id'];
            // Remember resolution for the afterStateUpdated hook
            // and for label lookups on edit / preload.
            self::cacheStructureResolution($id, [
                'name' => (string) $r['name'],
                'region_id' => (int) $r['region_id'],
                'solar_system_id' => (int) $r['solar_system_id'],
                'system_name' => (string) $r['system_name'],
            ]);

            $regionName = self::regionNameFor((int) $r['region_id']);
            $options[$id] = sprintf(
                '%s (%s, %s)',
                $r['name'],
                $r['system_name'],
                $regionName ?? 'region '.$r['region_id'],
            );
        }

        return $options;
    }

    /**
     * Label for an already-selected player structure ID. Used by the
     * Select on edit / on preload round-trip. We try the request-
     * local resolution cache first; if the row is being edited and
     * was selected in a previous request, fall back to the stored
     * display name on the MarketWatchedLocation row (which the
     * afterStateUpdated hook set on create).
     */
    private static function labelForPlayerStructure(int $structureId): string
    {
        $resolved = self::cachedStructureResolution($structureId);
        if ($resolved !== null) {
            $regionName = self::regionNameFor($resolved['region_id']);

            return sprintf(
                '%s (%s, %s)',
                $resolved['name'],
                $resolved['system_name'],
                $regionName ?? 'region '.$resolved['region_id'],
            );
        }

        $row = MarketWatchedLocation::query()
            ->where('location_id', $structureId)
            ->where('location_type', MarketWatchedLocation::LOCATION_TYPE_PLAYER_STRUCTURE)
            ->first();
        if ($row?->name) {
            return (string) $row->name;
        }

        return 'structure '.$structureId;
    }

    /**
     * Request-local cache for ESI-resolved structures. The picker
     * resolves once during search; the afterStateUpdated hook reads
     * it back without touching ESI again.
     *
     * @param  array{name: string, region_id: int, solar_system_id: int, system_name: string}  $payload
     */
    private static function cacheStructureResolution(int $structureId, array $payload): void
    {
        self::$structureCache[$structureId] = $payload;
    }

    /**
     * @return array{name: string, region_id: int, solar_system_id: int, system_name: string}|null
     */
    private static function cachedStructureResolution(int $structureId): ?array
    {
        return self::$structureCache[$structureId] ?? null;
    }

    /** @var array<int, array{name: string, region_id: int, solar_system_id: int, system_name: string}> */
    private static array $structureCache = [];

    private static function regionNameFor(int $regionId): ?string
    {
        $region = Region::find($regionId);

        return $region?->name;
    }

    private static function notifyPickerError(string $title, string $body): void
    {
        try {
            Notification::make()
                ->title($title)
                ->body($body)
                ->danger()
                ->send();
        } catch (Throwable $e) {
            // Notifications require a Livewire / Filament request
            // context; if we're called from a test or CLI, swallow.
        }
    }
}

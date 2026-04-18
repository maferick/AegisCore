<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domains\KillmailsBattleTheaters\Models\ShipClassCategoryMapping;
use App\Filament\Resources\ShipClassCategoryMappingResource\Pages;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /admin/ship-class-categories — manage the ship-type → role category
 * mapping that Spec 4 feature extraction reads at compute time.
 *
 * The mapping is seeded via migration for ~90 commonly-fielded hulls.
 * Admins extend it here as new hulls show up in real battles — the
 * "Unclassified ships" page highlights hulls the extractor has
 * flagged via WARN logs.
 *
 * Categories map to role intent:
 *   logi     remote-reps support (Guardian, Scimitar, Basilisk, …)
 *   bomber   stealth bombers
 *   command  command ships, command dessies, Monitor
 *   tackle   fast frigates, HICs, dictors, interceptors
 *   mainline DPS mainline (BC/HAC/BS/T3D/AF)
 *   other    hull known, outside the five above — first-class value,
 *            set explicitly when a hull is seen but doesn't fit
 *
 * The ship_type_id is pinned on edit; to retype a mapping, delete and
 * re-create. The list page enforces a search + category filter so the
 * table scales to thousands of rows without pagination noise.
 */
class ShipClassCategoryMappingResource extends Resource
{
    protected static ?string $model = ShipClassCategoryMapping::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rocket-launch';

    protected static string|UnitEnum|null $navigationGroup = 'Classification';

    protected static ?string $navigationLabel = 'Ship class categories';

    protected static ?string $modelLabel = 'ship class category';

    protected static ?string $pluralModelLabel = 'ship class categories';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'ship-class-categories';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Mapping')
                ->schema([
                    Select::make('ship_type_id')
                        ->label('Ship')
                        ->helperText('Published EVE hull. Pinned on edit — delete + recreate to retype.')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(
                            fn (string $search): array => DB::table('ref_item_types')
                                ->where('published', 1)
                                ->where('name', 'like', "%{$search}%")
                                ->orderBy('name')
                                ->limit(50)
                                ->pluck('name', 'id')
                                ->all()
                        )
                        ->getOptionLabelUsing(
                            fn ($value): ?string => DB::table('ref_item_types')->where('id', $value)->value('name')
                        )
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit'),

                    Select::make('category')
                        ->label('Category')
                        ->options(ShipClassCategoryMapping::categoryOptions())
                        ->required()
                        ->native(false),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $q) => $q->leftJoin('ref_item_types', 'ref_item_types.id', '=', 'ship_class_category_mapping.ship_type_id')
                    ->select('ship_class_category_mapping.*', 'ref_item_types.name as ship_name')
            )
            ->defaultSort('ship_name')
            ->columns([
                TextColumn::make('ship_name')
                    ->label('Ship')
                    ->searchable(query: fn (Builder $q, string $s) => $q->where('ref_item_types.name', 'like', "%{$s}%"))
                    ->sortable(),

                TextColumn::make('ship_type_id')
                    ->label('Type ID')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'logi' => 'success',
                        'command' => 'warning',
                        'bomber' => 'info',
                        'tackle' => 'primary',
                        'mainline' => 'gray',
                        'other' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('computed_at')
                    ->label('Added')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(ShipClassCategoryMapping::categoryOptions()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShipClassCategoryMappings::route('/'),
            'create' => Pages\CreateShipClassCategoryMapping::route('/create'),
            'edit' => Pages\EditShipClassCategoryMapping::route('/{record}/edit'),
            'unclassified' => Pages\UnclassifiedShips::route('/unclassified'),
        ];
    }
}

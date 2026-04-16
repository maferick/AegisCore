<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domains\UsersCharacters\Models\CoalitionBloc;
use App\Filament\Resources\CoalitionBlocResource\Pages;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * /admin/coalition-blocs — manage the coalition bloc registry.
 *
 * Blocs are the top-level groupings (WinterCo, Imperium, PanFam, etc.)
 * that the classification system uses to tag alliances/corps and drive
 * friendly/hostile/neutral alignment for viewers.
 *
 * Admins can rename blocs, toggle active status, and adjust default
 * roles. The `bloc_code` is locked on edit — it's the stable key
 * referenced by entity labels, seeder data, and resolver logic.
 */
class CoalitionBlocResource extends Resource
{
    protected static ?string $model = CoalitionBloc::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static string|UnitEnum|null $navigationGroup = 'Classification';

    protected static ?string $navigationLabel = 'Coalition blocs';

    protected static ?string $modelLabel = 'coalition bloc';

    protected static ?string $pluralModelLabel = 'coalition blocs';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'coalition-blocs';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Bloc identity')
                ->schema([
                    TextInput::make('bloc_code')
                        ->label('Code')
                        ->helperText(
                            'Short stable identifier (e.g. "wc", "cfc"). Used in '
                            .'raw labels like "wc.member". Locked on edit — entity '
                            .'labels reference this value.'
                        )
                        ->required()
                        ->maxLength(32)
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit'),

                    TextInput::make('display_name')
                        ->label('Display name')
                        ->helperText('Human-readable name shown in the UI (e.g. "Imperium", "WinterCo").')
                        ->required()
                        ->maxLength(100),
                ])
                ->columns(2),

            Section::make('Settings')
                ->schema([
                    Select::make('default_role')
                        ->label('Default role')
                        ->options([
                            CoalitionBloc::ROLE_COMBAT => 'Combat',
                            CoalitionBloc::ROLE_SUPPORT => 'Support',
                            CoalitionBloc::ROLE_LOGISTICS => 'Logistics',
                            CoalitionBloc::ROLE_RENTER => 'Renter',
                        ])
                        ->default(CoalitionBloc::ROLE_COMBAT)
                        ->required()
                        ->native(false),

                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText(
                            'Inactive blocs are hidden from pickers and ignored by '
                            .'the inference service. Existing labels referencing '
                            .'this bloc stay intact but become inert.'
                        ),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('display_name')
            ->columns([
                TextColumn::make('display_name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('bloc_code')
                    ->label('Code')
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->sortable(),

                TextColumn::make('default_role')
                    ->label('Default role')
                    ->badge()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('entity_labels_count')
                    ->label('Labels')
                    ->counts('entityLabels')
                    ->sortable()
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('On')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCoalitionBlocs::route('/'),
            'create' => Pages\CreateCoalitionBloc::route('/create'),
            'edit' => Pages\EditCoalitionBloc::route('/{record}/edit'),
        ];
    }
}

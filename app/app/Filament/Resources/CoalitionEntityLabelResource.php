<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domains\UsersCharacters\Models\CoalitionBloc;
use App\Domains\UsersCharacters\Models\CoalitionEntityLabel;
use App\Domains\UsersCharacters\Models\CoalitionRelationshipType;
use App\Filament\Resources\CoalitionEntityLabelResource\Pages;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
 * /admin/coalition-entity-labels — admin surface for the classification
 * system's normalised coalition label registry.
 *
 * Each row pins one (bloc, relationship) pair to a specific corporation
 * or alliance. Labels seed the viewer-bloc inference on /account/settings
 * and (later) the full resolver's bloc-inheritance precedence step. See
 * the `coalition_entity_labels` migration header + ADR-0001-style prose
 * in `App\Domains\UsersCharacters\Services\ViewerBlocInferenceService`.
 *
 * Phase 1 scope:
 *
 *   - Browse + filter all labels.
 *   - Create a label by picking bloc + relationship + source and
 *     typing the CCP entity ID (corp or alliance). `raw_label` is
 *     auto-generated as `{bloc_code}.{relationship_code}` unless the
 *     admin overrides it — the verbatim string is still stored so
 *     future bulk imports that carry a historical raw format stay
 *     faithful.
 *   - Edit: toggle `is_active`, change source, re-assign bloc /
 *     relationship. Changing entity_type or entity_id is blocked on
 *     edit to keep the unique-key semantics stable — delete + recreate
 *     if the target really changed.
 *   - Delete: no guarded rows. Uniqueness includes `source`, so
 *     removing a manually-tagged label won't collide with later
 *     imports.
 *
 * Not yet in scope (deferred):
 *
 *   - Name lookup / display for the entity ID. We have no player-side
 *     corporations / alliances ref table yet; a later slice adds
 *     ESI-backed resolution for the picker label. For now admins paste
 *     the numeric CCP ID from evewho / zkillboard.
 *   - Bulk import from CSV or zkill tag lists. The `source` column
 *     already accommodates it; the UI does not.
 */
class CoalitionEntityLabelResource extends Resource
{
    protected static ?string $model = CoalitionEntityLabel::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|UnitEnum|null $navigationGroup = 'Classification';

    protected static ?string $navigationLabel = 'Coalition labels';

    protected static ?string $modelLabel = 'coalition label';

    protected static ?string $pluralModelLabel = 'coalition labels';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'coalition-entity-labels';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Target entity')
                ->description(
                    'Which corp or alliance this label attaches to. Entity '
                    .'type and ID are part of the row\'s uniqueness key — '
                    .'changing them on edit would effectively relocate the '
                    .'label, which is error-prone. Delete + recreate if the '
                    .'target really changed.'
                )
                ->schema([
                    Select::make('entity_type')
                        ->label('Entity type')
                        ->options([
                            CoalitionEntityLabel::ENTITY_ALLIANCE => 'Alliance',
                            CoalitionEntityLabel::ENTITY_CORPORATION => 'Corporation',
                        ])
                        ->required()
                        ->native(false)
                        ->default(CoalitionEntityLabel::ENTITY_ALLIANCE)
                        ->disabledOn('edit'),

                    TextInput::make('entity_id')
                        ->label('CCP entity ID')
                        ->helperText(
                            'The CCP corporation_id or alliance_id for the target. '
                            .'Look up from evewho.com or zkillboard if you don\'t '
                            .'have it handy. An ID picker lands in a later slice '
                            .'once the player-side corp/alliance ref table exists.'
                        )
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->disabledOn('edit'),
                ])
                ->columns(2),

            Section::make('Classification')
                ->description(
                    'Which coalition bloc this entity belongs to and in what '
                    .'capacity. Member / affiliate / allied inherit alignment '
                    .'from the bloc; renter does not (rental contracts don\'t '
                    .'imply diplomatic alignment).'
                )
                ->schema([
                    Select::make('bloc_id')
                        ->label('Bloc')
                        ->relationship('bloc', 'display_name', fn ($query) => $query->where('is_active', true)->orderBy('display_name'))
                        ->required()
                        ->native(false)
                        ->preload()
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function ($state, $set, $get) {
                            self::maybeAutofillRawLabel($get, $set);
                        }),

                    Select::make('relationship_type_id')
                        ->label('Relationship')
                        ->relationship('relationshipType', 'display_name', fn ($query) => $query->orderBy('display_order'))
                        ->required()
                        ->native(false)
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function ($state, $set, $get) {
                            self::maybeAutofillRawLabel($get, $set);
                        }),

                    TextInput::make('raw_label')
                        ->label('Raw label')
                        ->helperText(
                            'Auto-fills to "{bloc_code}.{relationship_code}" when '
                            .'both are picked (e.g. "wc.member"). Override if this '
                            .'label was entered or imported with a different '
                            .'verbatim format. Part of the row\'s uniqueness key.'
                        )
                        ->required()
                        ->maxLength(100),

                    Select::make('source')
                        ->label('Source')
                        ->options([
                            CoalitionEntityLabel::SOURCE_MANUAL => 'Manual (admin tag)',
                            CoalitionEntityLabel::SOURCE_IMPORT => 'Import',
                            CoalitionEntityLabel::SOURCE_SEED => 'Seed',
                        ])
                        ->default(CoalitionEntityLabel::SOURCE_MANUAL)
                        ->required()
                        ->native(false)
                        ->helperText(
                            'Tracks provenance. Part of the uniqueness key, so '
                            .'the same (entity, raw_label) can legitimately come '
                            .'from two sources and count as independent agreement.'
                        ),

                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText(
                            'Inactive labels keep their row and historical audit '
                            .'trail but are ignored by the inference service and '
                            .'the resolver.'
                        ),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('entity_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => $state === CoalitionEntityLabel::ENTITY_ALLIANCE ? 'primary' : 'gray'),

                TextColumn::make('entity_id')
                    ->label('Entity ID')
                    ->numeric()
                    ->copyable()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('raw_label')
                    ->label('Label')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('warning'),

                TextColumn::make('bloc.display_name')
                    ->label('Bloc')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('relationshipType.display_name')
                    ->label('Relationship')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        CoalitionEntityLabel::SOURCE_MANUAL => 'success',
                        CoalitionEntityLabel::SOURCE_SEED => 'gray',
                        default => 'warning',
                    })
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
            ->filters([
                SelectFilter::make('entity_type')
                    ->options([
                        CoalitionEntityLabel::ENTITY_ALLIANCE => 'Alliances',
                        CoalitionEntityLabel::ENTITY_CORPORATION => 'Corporations',
                    ]),
                SelectFilter::make('bloc_id')
                    ->label('Bloc')
                    ->relationship('bloc', 'display_name', fn ($query) => $query->where('is_active', true)->orderBy('display_name'))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('relationship_type_id')
                    ->label('Relationship')
                    ->relationship('relationshipType', 'display_name', fn ($query) => $query->orderBy('display_order'))
                    ->preload(),
                SelectFilter::make('source')
                    ->options([
                        CoalitionEntityLabel::SOURCE_MANUAL => 'Manual',
                        CoalitionEntityLabel::SOURCE_IMPORT => 'Import',
                        CoalitionEntityLabel::SOURCE_SEED => 'Seed',
                    ]),
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCoalitionEntityLabels::route('/'),
            'create' => Pages\CreateCoalitionEntityLabel::route('/create'),
            'edit' => Pages\EditCoalitionEntityLabel::route('/{record}/edit'),
        ];
    }

    // -- helpers ----------------------------------------------------------

    /**
     * Autofill `raw_label` from the picked bloc + relationship when both
     * are set and the admin hasn't typed a custom value. Preserves any
     * manual override — only overwrites blanks.
     */
    private static function maybeAutofillRawLabel(callable $get, callable $set): void
    {
        $blocId = $get('bloc_id');
        $relId = $get('relationship_type_id');

        if (! $blocId || ! $relId) {
            return;
        }

        $bloc = CoalitionBloc::find($blocId);
        $rel = CoalitionRelationshipType::find($relId);
        if ($bloc === null || $rel === null) {
            return;
        }

        $suggested = "{$bloc->bloc_code}.{$rel->relationship_code}";

        $current = trim((string) ($get('raw_label') ?? ''));
        if ($current === '') {
            $set('raw_label', $suggested);

            return;
        }

        // If the current value looks like a previous auto-suggestion
        // (matches some bloc.relationship pattern from our taxonomy),
        // refresh it. Otherwise assume the admin has hand-edited and
        // leave it alone.
        if (preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $current) === 1) {
            $set('raw_label', $suggested);
        }
    }
}

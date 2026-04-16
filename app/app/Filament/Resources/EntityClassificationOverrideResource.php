<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domains\UsersCharacters\Models\EntityClassificationOverride;
use App\Domains\UsersCharacters\Models\ViewerContext;
use App\Filament\Resources\EntityClassificationOverrideResource\Pages;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
 * /admin/entity-classification-overrides — admin CRUD for the resolver's
 * manual-override layer.
 *
 * Two scopes in one table (see the migration header for precedence
 * details):
 *
 *   - `global` — applies to every viewer. Used sparingly; typical
 *     cases are "this known-hostile alliance should never be marked
 *     friendly regardless of evidence" or "this defunct alliance is
 *     permanently neutral."
 *   - `viewer` — scoped to a single donor. Donors create their own
 *     from /account/settings; admins can create them on behalf of a
 *     donor here (e.g. escalated support case).
 *
 * Invariant enforced by the model's saving hook:
 *
 *     scope = 'global'  <=>  viewer_context_id IS NULL
 *     scope = 'viewer'  <=>  viewer_context_id IS NOT NULL
 *
 * Filament's form validation mirrors the invariant client-side
 * (required-when rules on the viewer select) so admins get a pre-save
 * error instead of a DomainException bubbling up from the model.
 *
 * Phase-1 scope (current):
 *   - Browse + filter by scope / alignment / active / expired.
 *   - Create override of either scope.
 *   - Edit: change alignment, side_key, role, reason, expires_at,
 *     is_active. Scope + target + viewer_context are pinned on edit
 *     because they form the row's identity under the unique key.
 *   - Delete: hard delete (admin's call — donor-self overrides are
 *     soft-deleted via is_active=false on /account/settings).
 *
 * Not yet in scope:
 *   - Bulk alignment flip (e.g. "mark every WC-labelled alliance
 *     hostile to B2 viewers"). Use Phase 2 consensus signals when
 *     those exist instead of rubber-stamping in bulk.
 */
class EntityClassificationOverrideResource extends Resource
{
    protected static ?string $model = EntityClassificationOverride::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|UnitEnum|null $navigationGroup = 'Classification';

    protected static ?string $navigationLabel = 'Classification overrides';

    protected static ?string $modelLabel = 'classification override';

    protected static ?string $pluralModelLabel = 'classification overrides';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'entity-classification-overrides';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Scope')
                ->description(
                    'Global overrides apply to every viewer and are used '
                    .'sparingly — typical cases are "defunct alliance, pin '
                    .'as neutral" or "known-hostile, never flag friendly". '
                    .'Viewer-scope overrides apply only to the picked donor '
                    .'and take precedence over every other signal in the '
                    .'resolver chain except that donor\'s own in-game '
                    .'standing evidence.'
                )
                ->schema([
                    Select::make('scope_type')
                        ->label('Scope')
                        ->options([
                            EntityClassificationOverride::SCOPE_GLOBAL => 'Global (all viewers)',
                            EntityClassificationOverride::SCOPE_VIEWER => 'Viewer (single donor)',
                        ])
                        ->required()
                        ->native(false)
                        ->default(EntityClassificationOverride::SCOPE_GLOBAL)
                        ->live()
                        ->disabledOn('edit'),

                    // Viewer picker — searchable by character name via
                    // the ViewerContext -> Character relationship.
                    // Required when scope='viewer', hidden otherwise.
                    // Scope + viewer form part of the uniqueness key so
                    // the pair is pinned on edit.
                    Select::make('viewer_context_id')
                        ->label('Viewer (character)')
                        ->required(fn ($get) => $get('scope_type') === EntityClassificationOverride::SCOPE_VIEWER)
                        ->visible(fn ($get) => $get('scope_type') === EntityClassificationOverride::SCOPE_VIEWER)
                        ->disabledOn('edit')
                        ->searchable()
                        ->preload(false)
                        ->native(false)
                        ->getSearchResultsUsing(fn (string $search): array => self::searchViewerContexts($search))
                        ->getOptionLabelUsing(fn ($value) => self::labelForViewerContext((int) $value))
                        ->helperText(
                            'Searches all active viewer contexts by linked '
                            .'character name. A donor can own at most one '
                            .'viewer context today (Phase 1 scope).'
                        ),
                ])
                ->columns(2),

            Section::make('Target entity')
                ->description(
                    'Which corp or alliance the override applies to. Type '
                    .'and id are pinned on edit because they form the row\'s '
                    .'uniqueness key — delete + recreate if the target '
                    .'genuinely changed.'
                )
                ->schema([
                    Select::make('target_entity_type')
                        ->label('Entity type')
                        ->options([
                            EntityClassificationOverride::ENTITY_ALLIANCE => 'Alliance',
                            EntityClassificationOverride::ENTITY_CORPORATION => 'Corporation',
                        ])
                        ->required()
                        ->native(false)
                        ->default(EntityClassificationOverride::ENTITY_ALLIANCE)
                        ->disabledOn('edit'),

                    TextInput::make('target_entity_id')
                        ->label('CCP entity ID')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->disabledOn('edit')
                        ->helperText(
                            'The CCP corporation_id or alliance_id. '
                            .'evewho.com or zkillboard both surface these.'
                        ),
                ])
                ->columns(2),

            Section::make('Forced classification')
                ->description(
                    'What the resolver should return for this (viewer, '
                    .'target) pair. Side key and role are free-form tags '
                    .'consumed by downstream rendering; leave them empty '
                    .'unless you know the rendering surface wants them.'
                )
                ->schema([
                    Select::make('forced_alignment')
                        ->label('Alignment')
                        ->options([
                            EntityClassificationOverride::ALIGNMENT_FRIENDLY => 'Friendly',
                            EntityClassificationOverride::ALIGNMENT_HOSTILE => 'Hostile',
                            EntityClassificationOverride::ALIGNMENT_NEUTRAL => 'Neutral',
                            EntityClassificationOverride::ALIGNMENT_UNKNOWN => 'Unknown',
                        ])
                        ->required()
                        ->native(false),

                    TextInput::make('forced_side_key')
                        ->label('Side key (optional)')
                        ->maxLength(32)
                        ->helperText('Free-form grouping, e.g. "bloc-frontline".'),

                    TextInput::make('forced_role')
                        ->label('Role (optional)')
                        ->maxLength(32)
                        ->helperText('E.g. combat, logistics, renter.'),

                    Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->rows(3)
                        ->maxLength(500)
                        ->helperText(
                            'Why this override exists. Overrides without '
                            .'rationale rot fast — spend 10 seconds on a '
                            .'sentence here and save yourself future '
                            .'"why is this hostile?" forensics.'
                        ),

                    DateTimePicker::make('expires_at')
                        ->label('Expires at (optional)')
                        ->helperText(
                            'Leave empty for a permanent override. '
                            .'An expired override is ignored by the '
                            .'resolver — the row stays in the table for '
                            .'audit, it just stops firing.'
                        ),

                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Inactive overrides are skipped by the resolver.'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('scope_type')
                    ->label('Scope')
                    ->badge()
                    ->color(fn (string $state): string => $state === EntityClassificationOverride::SCOPE_GLOBAL ? 'warning' : 'gray'),

                TextColumn::make('viewerContext.character.name')
                    ->label('Viewer')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('target_entity_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => $state === EntityClassificationOverride::ENTITY_ALLIANCE ? 'primary' : 'gray'),

                TextColumn::make('target_entity_id')
                    ->label('Entity ID')
                    ->numeric()
                    ->copyable()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('forced_alignment')
                    ->label('Forced')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        EntityClassificationOverride::ALIGNMENT_FRIENDLY => 'success',
                        EntityClassificationOverride::ALIGNMENT_HOSTILE => 'danger',
                        EntityClassificationOverride::ALIGNMENT_NEUTRAL => 'gray',
                        default => 'warning',
                    }),

                TextColumn::make('reason')
                    ->label('Reason')
                    ->wrap()
                    ->limit(60)
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('On')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->since()
                    ->placeholder('never')
                    ->toggleable(),

                TextColumn::make('createdByCharacter.name')
                    ->label('Created by')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('scope_type')
                    ->options([
                        EntityClassificationOverride::SCOPE_GLOBAL => 'Global',
                        EntityClassificationOverride::SCOPE_VIEWER => 'Viewer',
                    ]),
                SelectFilter::make('target_entity_type')
                    ->options([
                        EntityClassificationOverride::ENTITY_ALLIANCE => 'Alliances',
                        EntityClassificationOverride::ENTITY_CORPORATION => 'Corporations',
                    ]),
                SelectFilter::make('forced_alignment')
                    ->options([
                        EntityClassificationOverride::ALIGNMENT_FRIENDLY => 'Friendly',
                        EntityClassificationOverride::ALIGNMENT_HOSTILE => 'Hostile',
                        EntityClassificationOverride::ALIGNMENT_NEUTRAL => 'Neutral',
                        EntityClassificationOverride::ALIGNMENT_UNKNOWN => 'Unknown',
                    ]),
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
                TernaryFilter::make('expired')
                    ->label('Expiry')
                    ->placeholder('All')
                    ->trueLabel('Expired')
                    ->falseLabel('Still valid')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('expires_at')->where('expires_at', '<=', now()),
                        false: fn ($query) => $query->where(function ($q): void {
                            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                        }),
                        blank: fn ($query) => $query,
                    ),
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
            'index' => Pages\ListEntityClassificationOverrides::route('/'),
            'create' => Pages\CreateEntityClassificationOverride::route('/create'),
            'edit' => Pages\EditEntityClassificationOverride::route('/{record}/edit'),
        ];
    }

    // -- helpers ----------------------------------------------------------

    /**
     * Searchable viewer-context picker by linked character name.
     * Returns the top 50 matches as [id => "Character Name #ccp_id"].
     *
     * @return array<int, string>
     */
    private static function searchViewerContexts(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        $contexts = ViewerContext::query()
            ->whereHas('character', function ($q) use ($search): void {
                if (ctype_digit($search)) {
                    $q->where('character_id', '=', (int) $search);
                } else {
                    $q->where('name', 'like', '%'.$search.'%');
                }
            })
            ->with('character')
            ->where('is_active', true)
            ->limit(50)
            ->get();

        $options = [];
        foreach ($contexts as $context) {
            $options[(int) $context->id] = self::formatViewerLabel($context);
        }

        return $options;
    }

    private static function labelForViewerContext(int $viewerContextId): string
    {
        $context = ViewerContext::query()->with('character')->find($viewerContextId);
        if ($context === null) {
            return "viewer #{$viewerContextId}";
        }

        return self::formatViewerLabel($context);
    }

    private static function formatViewerLabel(ViewerContext $context): string
    {
        $char = $context->character;
        if ($char === null) {
            return "viewer #{$context->id} (character missing)";
        }

        return sprintf('%s #%d', $char->name, $char->character_id);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\ShipClassCategoryMappingResource\Pages;

use App\Domains\KillmailsBattleTheaters\Models\ShipClassCategoryMapping;
use App\Filament\Resources\ShipClassCategoryMappingResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * /admin/ship-class-categories/unclassified — review queue for hulls
 * that Spec 4 feature extraction is currently labelling 'other' by
 * fall-through (seen in battle but not in ship_class_category_mapping).
 *
 * The list is derived from the live killmail corpus: for every
 * ship_type_id that appears in a Spec-4-eligible attacker row and
 * has no row in ship_class_category_mapping, show the hull name,
 * pilot count, and first/last-seen timestamps. One-click "Classify"
 * opens a modal with category picker and inserts the mapping row.
 *
 * The query is eager-materialized into a SQLite-compatible subquery
 * so Filament's table filter + pagination still work without a model.
 */
class UnclassifiedShips extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ShipClassCategoryMappingResource::class;

    protected string $view = 'filament.resources.ship-class-category-mapping.unclassified';

    protected static ?string $title = 'Unclassified ships';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->baseQuery())
            ->defaultSort('pilot_count', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('ship_name')
                    ->label('Ship')
                    ->searchable(query: fn (Builder $q, string $s) => $q->where('ref_item_types.name', 'like', "%{$s}%"))
                    ->sortable(),

                TextColumn::make('ship_type_id')
                    ->label('Type ID')
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('pilot_count')
                    ->label('Pilots')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('appearances')
                    ->label('Attacker rows')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('last_seen')
                    ->label('Last seen')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('classify')
                    ->label('Classify')
                    ->icon('heroicon-o-tag')
                    ->color('primary')
                    ->schema([
                        Select::make('category')
                            ->label('Category')
                            ->options(ShipClassCategoryMapping::categoryOptions())
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (array $data, $record): void {
                        ShipClassCategoryMapping::updateOrCreate(
                            ['ship_type_id' => (int) $record->ship_type_id],
                            ['category' => $data['category'], 'computed_at' => now()],
                        );
                        Notification::make()
                            ->title("Classified {$record->ship_name} as {$data['category']}")
                            ->success()
                            ->send();
                    })
                    ->modalHeading(fn ($record) => "Classify {$record->ship_name}")
                    ->modalSubmitActionLabel('Save'),
            ]);
    }

    /**
     * Eloquent-compatible base query. We wrap the aggregation in a
     * subquery so Filament's `->query()` can treat each row like a
     * model instance; the primary key is ship_type_id.
     */
    protected function baseQuery(): Builder
    {
        /** @var QueryBuilder $sub */
        $sub = DB::table('killmail_attackers AS a')
            ->join('battle_theater_killmails AS btk', 'btk.killmail_id', '=', 'a.killmail_id')
            ->join('killmails AS k', 'k.killmail_id', '=', 'a.killmail_id')
            ->leftJoin('ship_class_category_mapping AS sccm', 'sccm.ship_type_id', '=', 'a.ship_type_id')
            ->leftJoin('ref_item_types', 'ref_item_types.id', '=', 'a.ship_type_id')
            ->whereNotNull('a.ship_type_id')
            ->whereNull('sccm.ship_type_id')
            ->whereIn('a.character_id', function (QueryBuilder $q): void {
                $q->select('character_id')
                  ->from('battle_character_sub_fleet_membership');
            })
            ->groupBy('a.ship_type_id', 'ref_item_types.name')
            ->selectRaw('a.ship_type_id as ship_type_id, ref_item_types.name as ship_name, '
                . 'COUNT(DISTINCT a.character_id) as pilot_count, '
                . 'COUNT(*) as appearances, '
                . 'MAX(k.killed_at) as last_seen');

        // Wrap DB query in an Eloquent-model query so Filament's Table
        // integration (sort, search, pagination) works. Use the
        // ShipClassCategoryMapping model as a carrier; we re-point the
        // from clause at the subquery and surface the aggregated
        // columns as model attributes via raw SQL.
        return ShipClassCategoryMapping::query()
            ->fromSub($sub, 'unclassified')
            ->select('unclassified.*');
    }
}

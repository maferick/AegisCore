<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Reference\Models\Constellation;
use App\Reference\Models\Region;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Filament\Pages\Page;

/**
 * /admin/universe-map — Filament demo + ops surface for the renderer.
 *
 * The form drives the same `<x-map.renderer>` component anyone else can
 * drop into a Blade view. Treat this page as the live reference of the
 * component's prop set.
 */
class UniverseMap extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.universe-map';

    protected static ?string $title = 'Universe Map';

    protected static ?string $navigationLabel = 'Universe Map';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-europe-africa';

    protected static string|\UnitEnum|null $navigationGroup = 'Tools';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'universe-map';

    public string $scope = 'universe';

    public string $detail = 'aggregated';

    public ?int $regionId = null;

    public ?int $constellationId = null;

    /** @var array<int, int> */
    public array $systemIds = [];

    public int $hops = 1;

    public string $labelMode = 'hover';

    public string $colorBy = 'security';

    public function mount(): void
    {
        $this->form->fill([
            'scope' => $this->scope,
            'detail' => $this->detail,
            'regionId' => $this->regionId,
            'constellationId' => $this->constellationId,
            'systemIds' => $this->systemIds,
            'hops' => $this->hops,
            'labelMode' => $this->labelMode,
            'colorBy' => $this->colorBy,
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->statePath('')
            ->columns(12)
            ->schema([
                Select::make('scope')
                    ->label('Scope')
                    ->options([
                        'universe' => 'Universe',
                        'region' => 'Region',
                        'constellation' => 'Constellation',
                        'subgraph' => 'Subgraph (system list)',
                    ])
                    ->live()
                    ->columnSpan(3)
                    ->required(),

                Select::make('detail')
                    ->label('Detail')
                    ->options([
                        'aggregated' => 'Aggregated (region centroids)',
                        'dense' => 'Dense (every system)',
                    ])
                    ->visible(fn ($get) => $get('scope') === 'universe')
                    ->columnSpan(3),

                Select::make('regionId')
                    ->label('Region')
                    ->options(fn () => Region::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->live()
                    ->visible(fn ($get) => $get('scope') === 'region' || $get('scope') === 'constellation')
                    ->columnSpan(3),

                Select::make('constellationId')
                    ->label('Constellation')
                    ->options(function ($get) {
                        $rid = $get('regionId');
                        $q = Constellation::query()->orderBy('name');
                        if ($rid) {
                            $q->where('region_id', $rid);
                        }

                        return $q->pluck('name', 'id')->all();
                    })
                    ->searchable()
                    ->visible(fn ($get) => $get('scope') === 'constellation')
                    ->columnSpan(3),

                TagsInput::make('systemIds')
                    ->label('System IDs')
                    ->placeholder('30000142, 30045349, …')
                    ->helperText('Anchor systems for the subgraph.')
                    ->columnSpan(8)
                    ->visible(fn ($get) => $get('scope') === 'subgraph'),

                TextInput::make('hops')
                    ->label('Hops')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(4)
                    ->columnSpan(4)
                    ->visible(fn ($get) => $get('scope') === 'subgraph'),

                Select::make('labelMode')
                    ->label('Labels')
                    ->options([
                        'hover' => 'On hover / when zoomed',
                        'always' => 'Always visible',
                        'hidden' => 'Hidden',
                    ])
                    ->columnSpan(3),

                Select::make('colorBy')
                    ->label('Color')
                    ->options([
                        'security' => 'Security status',
                        'region' => 'Region',
                    ])
                    ->columnSpan(3),
            ]);
    }

    /**
     * Normalise the system_ids prop — Filament's TagsInput hands us
     * strings; cast to int and drop empties before passing to the
     * Blade component.
     *
     * @return array<int, int>
     */
    public function getSystemIdsForRender(): array
    {
        return array_values(array_filter(array_map(
            static fn ($v) => (int) $v,
            $this->systemIds ?? []
        )));
    }
}

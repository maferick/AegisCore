<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Domains\KillmailsBattleTheaters\Models\BattleTheater;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Portal "Battles" index — list of recent battle theaters sorted by
 * end time. Detail page at /portal/battles/{id} (BattleTheaterDetail).
 *
 * Per ADR-0006 § 2, side labels are viewer-relative, so this page does
 * NOT show "us vs them" columns — those resolve on the detail page
 * where we can load the viewer's bloc. The list here is deliberately
 * neutral: system, time, kills, ISK, participants. Sorting on ISK or
 * participants is the common filter for "find me a big fight".
 */
class Battles extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-fire';

    protected static ?string $navigationLabel = 'Battles';

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Battles';

    protected static ?string $slug = 'battles';

    protected string $view = 'filament.portal.pages.battles';

    public function table(Table $table): Table
    {
        // Resolve viewer's corp / alliance / bloc-sibling alliances once
        // so the table can tag battles the viewer actually fought in.
        [$viewerCorpId, $viewerAllianceIds] = $this->resolveViewerEntities();

        return $table
            ->query(function () use ($viewerCorpId, $viewerAllianceIds): Builder {
                $q = BattleTheater::query()->with(['primarySystem:id,name', 'region:id,name']);
                if ($viewerCorpId !== null || $viewerAllianceIds !== []) {
                    $sub = DB::table('battle_theater_participants')
                        ->selectRaw('1')
                        ->whereColumn('battle_theater_participants.theater_id', 'battle_theaters.id');
                    if ($viewerAllianceIds !== []) {
                        $sub->where(function ($w) use ($viewerCorpId, $viewerAllianceIds): void {
                            $w->whereIn('battle_theater_participants.alliance_id', $viewerAllianceIds);
                            if ($viewerCorpId !== null) {
                                $w->orWhere('battle_theater_participants.corporation_id', $viewerCorpId);
                            }
                        });
                    } elseif ($viewerCorpId !== null) {
                        $sub->where('battle_theater_participants.corporation_id', $viewerCorpId);
                    }
                    $q->selectRaw('battle_theaters.*, EXISTS('.$sub->toSql().') AS viewer_involved', $sub->getBindings());
                } else {
                    $q->selectRaw('battle_theaters.*, 0 AS viewer_involved');
                }
                return $q;
            })
            ->defaultSort('end_time', 'desc')
            ->recordClasses(fn (BattleTheater $r): ?string => ((int) ($r->viewer_involved ?? 0) === 1) ? 'bt-involved-row' : null)
            ->columns([
                TextColumn::make('label')
                    ->label('Battle')
                    ->getStateUsing(fn (BattleTheater $r): string => $r->primarySystem?->name ?? "#{$r->primary_system_id}")
                    ->description(fn (BattleTheater $r): string => $r->region?->name ?? '—')
                    ->searchable(query: function (Builder $q, string $search): Builder {
                        return $q->whereHas('primarySystem', fn (Builder $s) => $s->where('name', 'like', "%{$search}%"));
                    }),

                TextColumn::make('end_time')
                    ->label('When')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->description(fn (BattleTheater $r): string => $r->start_time?->diffForHumans($r->end_time, short: true) ?? ''),

                TextColumn::make('total_kills')
                    ->label('Kills')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('participant_count')
                    ->label('Pilots')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('system_count')
                    ->label('Systems')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('total_isk_lost')
                    ->label('ISK lost')
                    ->sortable()
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => self::formatIsk((float) $state)),

                BadgeColumn::make('locked_at')
                    ->label('State')
                    ->getStateUsing(fn (BattleTheater $r): string => $r->locked_at ? 'Locked' : 'Live')
                    ->colors([
                        'success' => 'Live',
                        'gray' => 'Locked',
                    ])
                    ->toggleable(),
            ])
            ->recordUrl(fn (BattleTheater $record): string => BattleTheaterDetail::getUrl(['record' => $record->id]));
    }

    /**
     * @return array{0: int|null, 1: list<int>}  [viewerCorpId, viewerAllianceIds]
     *
     * viewerAllianceIds includes the viewer's own alliance plus every
     * alliance tagged with the same bloc_id in coalition_entity_labels,
     * so the blue highlight fires for any bloc-friendly participation —
     * not just the viewer's own alliance.
     */
    private function resolveViewerEntities(): array
    {
        $user = Auth::user();
        if ($user === null) return [null, []];
        $char = $user->characters()->first();
        if ($char === null) return [null, []];

        $corpId = (int) ($char->corporation_id ?? 0) ?: null;
        $allyId = (int) ($char->alliance_id ?? 0) ?: null;

        $allianceIds = [];
        if ($allyId !== null) {
            $allianceIds[] = $allyId;
            $blocId = DB::table('coalition_entity_labels')
                ->where('entity_type', 'alliance')
                ->where('entity_id', $allyId)
                ->where('is_active', 1)
                ->value('bloc_id');
            if ($blocId) {
                $siblings = DB::table('coalition_entity_labels')
                    ->where('entity_type', 'alliance')
                    ->where('bloc_id', $blocId)
                    ->where('is_active', 1)
                    ->pluck('entity_id')
                    ->map(fn ($v) => (int) $v)
                    ->all();
                $allianceIds = array_values(array_unique(array_merge($allianceIds, $siblings)));
            }
        }
        return [$corpId, $allianceIds];
    }

    /**
     * Compact ISK formatter: 1.2T / 847B / 23.4M / 1.7k / plain.
     * Used both here and on the detail page, kept static so
     * blade can call it directly without a service lookup.
     */
    public static function formatIsk(float $isk): string
    {
        $abs = abs($isk);
        if ($abs >= 1e12) {
            return number_format($isk / 1e12, 2).'T';
        }
        if ($abs >= 1e9) {
            return number_format($isk / 1e9, 2).'B';
        }
        if ($abs >= 1e6) {
            return number_format($isk / 1e6, 1).'M';
        }
        if ($abs >= 1e3) {
            return number_format($isk / 1e3, 1).'k';
        }

        return number_format($isk, 0);
    }
}

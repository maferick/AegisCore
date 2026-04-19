<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Domains\KillmailsBattleTheaters\Models\BattleTheater;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
                    // Threshold for "actively involved" — a random
                    // single pilot who happened to warp through shouldn't
                    // flag the battle as ours. 5 or more pilots from
                    // our corp / bloc alliances = a real deployment.
                    $minInvolvedPilots = 5;
                    $countSub = DB::table('battle_theater_participants')
                        ->selectRaw('COUNT(DISTINCT battle_theater_participants.character_id)')
                        ->whereColumn('battle_theater_participants.theater_id', 'battle_theaters.id');
                    if ($viewerAllianceIds !== []) {
                        $countSub->where(function ($w) use ($viewerCorpId, $viewerAllianceIds): void {
                            $w->whereIn('battle_theater_participants.alliance_id', $viewerAllianceIds);
                            if ($viewerCorpId !== null) {
                                $w->orWhere('battle_theater_participants.corporation_id', $viewerCorpId);
                            }
                        });
                    } elseif ($viewerCorpId !== null) {
                        $countSub->where('battle_theater_participants.corporation_id', $viewerCorpId);
                    }
                    $q->selectRaw(
                        sprintf('battle_theaters.*, CASE WHEN (%s) >= ? THEN 1 ELSE 0 END AS viewer_involved', $countSub->toSql()),
                        array_merge($countSub->getBindings(), [$minInvolvedPilots]),
                    );
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
                        // whereHas on primarySystem trips the Laravel 12
                        // relation introspection path when the outer
                        // query already has a selectRaw override
                        // (battle_theaters.* + viewer_involved). Use a
                        // join against solar_systems directly — same
                        // result, no relation lookup needed.
                        return $q->whereExists(function ($sub) use ($search): void {
                            $sub->select(DB::raw(1))
                                ->from('ref_solar_systems')
                                ->whereColumn('ref_solar_systems.id', 'battle_theaters.primary_system_id')
                                ->where('ref_solar_systems.name', 'like', "%{$search}%");
                        });
                    }),

                TextColumn::make('end_time')
                    ->label('When')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->description(fn (BattleTheater $r): string => $r->start_time?->diffForHumans($r->end_time, short: true) ?? ''),

                ViewColumn::make('top_sides')
                    ->label('Top sides')
                    ->view('filament.portal.columns.battle-top-sides')
                    ->viewData(fn (BattleTheater $r): array => [
                        'sides' => $this->topSidesFor((int) $r->id),
                    ]),

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
            ->filters([
                SelectFilter::make('min_kills')
                    ->label('Min kills')
                    ->options([
                        '10' => '10+',
                        '25' => '25+',
                        '50' => '50+',
                        '100' => '100+',
                        '250' => '250+',
                        '500' => '500+',
                        '1000' => '1,000+',
                    ])
                    ->query(fn (Builder $q, array $data): Builder => isset($data['value']) && $data['value'] !== null && $data['value'] !== ''
                        ? $q->where('battle_theaters.total_kills', '>=', (int) $data['value'])
                        : $q),
                SelectFilter::make('min_pilots')
                    ->label('Min pilots')
                    ->options([
                        '25' => '25+',
                        '50' => '50+',
                        '100' => '100+',
                        '250' => '250+',
                        '500' => '500+',
                        '1000' => '1,000+',
                        '2000' => '2,000+',
                    ])
                    ->query(fn (Builder $q, array $data): Builder => isset($data['value']) && $data['value'] !== null && $data['value'] !== ''
                        ? $q->where('battle_theaters.participant_count', '>=', (int) $data['value'])
                        : $q),
                SelectFilter::make('min_isk')
                    ->label('Min ISK destroyed')
                    ->options([
                        '1000000000' => '1 B+',
                        '5000000000' => '5 B+',
                        '25000000000' => '25 B+',
                        '100000000000' => '100 B+',
                        '500000000000' => '500 B+',
                        '1000000000000' => '1 T+',
                    ])
                    ->query(fn (Builder $q, array $data): Builder => isset($data['value']) && $data['value'] !== null && $data['value'] !== ''
                        ? $q->where('battle_theaters.total_isk_lost', '>=', (float) $data['value'])
                        : $q),
                SelectFilter::make('viewer_involved')
                    ->label('Involvement')
                    ->options([
                        '1' => 'Only battles my bloc fielded 5+ pilots',
                    ])
                    ->query(fn (Builder $q, array $data): Builder => ($data['value'] ?? null) === '1'
                        ? $q->where('viewer_involved', 1)
                        : $q),
            ])
            ->filtersFormColumns(4)
            ->recordUrl(fn (BattleTheater $record): string => BattleTheaterDetail::getUrl(['record' => $record->id]));
    }

    /**
     * Top-2 alliances on each side of a theater, by pilot count, for
     * the list thumbnail strip. "Side" here is approximated from the
     * kill graph — we take the two biggest alliances (most pilots),
     * then fold every remaining alliance onto whichever of the two
     * anchors their kills oppose the most. Skipping the full
     * BattleTheaterSideResolver (expensive Neo4j / standings lookups)
     * keeps the list page cheap.
     *
     * @return array{sideA: list<array{alliance_id: int, name: string, pilots: int}>, sideB: list<array{alliance_id: int, name: string, pilots: int}>}
     */
    private function topSidesFor(int $theaterId): array
    {
        $rows = DB::table('battle_theater_participants AS p')
            ->leftJoin('esi_entity_names AS n', function ($j): void {
                $j->on('n.entity_id', '=', 'p.alliance_id')
                  ->where('n.category', 'alliance');
            })
            ->where('p.theater_id', $theaterId)
            ->where('p.alliance_id', '>', 0)
            ->selectRaw('p.alliance_id, n.name AS alliance_name, COUNT(DISTINCT p.character_id) AS pilots')
            ->groupBy('p.alliance_id', 'n.name')
            ->orderByDesc('pilots')
            ->limit(6)
            ->get();

        if ($rows->isEmpty()) {
            return ['sideA' => [], 'sideB' => []];
        }

        $anchors = $rows->take(2)->pluck('alliance_id')->map(fn ($v) => (int) $v)->all();
        if (count($anchors) < 2) {
            // Only one alliance on field → everyone's Side A.
            return [
                'sideA' => $rows->take(2)->map($this->aMap())->all(),
                'sideB' => [],
            ];
        }
        [$anchorA, $anchorB] = $anchors;

        // Kill graph between anchorA and the rest, anchorB and the rest,
        // to decide which anchor each remaining alliance sided with.
        $killmailIds = DB::table('battle_theater_killmails')
            ->where('theater_id', $theaterId)
            ->pluck('killmail_id')
            ->all();

        $killEdges = collect();
        if ($killmailIds !== []) {
            $killEdges = DB::table('killmails AS k')
                ->join('killmail_attackers AS ka', 'ka.killmail_id', '=', 'k.killmail_id')
                ->whereIn('k.killmail_id', $killmailIds)
                ->whereNotNull('k.victim_alliance_id')
                ->whereNotNull('ka.alliance_id')
                ->selectRaw('k.victim_alliance_id AS victim, ka.alliance_id AS attacker, COUNT(*) AS n')
                ->groupBy('k.victim_alliance_id', 'ka.alliance_id')
                ->get();
        }

        $side = [$anchorA => 'A', $anchorB => 'B'];
        foreach ($rows->skip(2) as $r) {
            $aid = (int) $r->alliance_id;
            $supportsA = 0; $supportsB = 0;
            foreach ($killEdges as $e) {
                if ((int) $e->attacker === $aid && (int) $e->victim === $anchorB) $supportsA += (int) $e->n;
                if ((int) $e->attacker === $anchorB && (int) $e->victim === $aid) $supportsA += (int) $e->n;
                if ((int) $e->attacker === $aid && (int) $e->victim === $anchorA) $supportsB += (int) $e->n;
                if ((int) $e->attacker === $anchorA && (int) $e->victim === $aid) $supportsB += (int) $e->n;
            }
            if ($supportsA > $supportsB) $side[$aid] = 'A';
            elseif ($supportsB > $supportsA) $side[$aid] = 'B';
            // ties: leave unassigned so they don't crowd the card
        }

        $out = ['sideA' => [], 'sideB' => []];
        foreach ($rows as $r) {
            $aid = (int) $r->alliance_id;
            $bucket = $side[$aid] ?? null;
            if ($bucket === null) continue;
            $key = $bucket === 'A' ? 'sideA' : 'sideB';
            if (count($out[$key]) >= 2) continue;
            $out[$key][] = [
                'alliance_id' => $aid,
                'name' => (string) ($r->alliance_name ?? "#$aid"),
                'pilots' => (int) $r->pilots,
            ];
        }
        return $out;
    }

    private function aMap(): callable
    {
        return fn ($r): array => [
            'alliance_id' => (int) $r->alliance_id,
            'name' => (string) ($r->alliance_name ?? '#'.$r->alliance_id),
            'pilots' => (int) $r->pilots,
        ];
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

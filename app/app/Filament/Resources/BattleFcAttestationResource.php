<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domains\KillmailsBattleTheaters\Models\BattleFcUserAttestation;
use App\Filament\Resources\BattleFcAttestationResource\Pages;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /admin/fc-attestations — admin-only read-only audit of donor-tier
 * FC attestations (Spec 6 Mode A admin break).
 *
 * Mode A discipline is preserved at user-facing surfaces (portal battle
 * reports, public battle reports, /portal/my-fc-attestations for
 * the submitter only). Admin access is an explicit architectural
 * break for:
 *   - donor engagement monitoring
 *   - Spec 7 truth-set review
 *   - abuse detection (single user flooding, obviously-wrong attestations)
 *
 * Auth: admins only (gated at the panel level by User::isAdmin() /
 * canAccessPanel). canCreate/canEdit/canDelete all disabled — the only
 * writer is the donor-tier portal control.
 *
 * Name resolution (user / alliance / character) is done per-row via
 * TextColumn::getStateUsing with keyed static maps so the base query
 * stays a plain Eloquent query (Filament's search + pagination break
 * when the base query is joined + aliased).
 */
class BattleFcAttestationResource extends Resource
{
    protected static ?string $model = BattleFcUserAttestation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static string|UnitEnum|null $navigationGroup = 'Classification';

    protected static ?string $navigationLabel = 'FC attestations';

    protected static ?string $modelLabel = 'FC attestation';

    protected static ?string $pluralModelLabel = 'FC attestations';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'fc-attestations';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('attested_at', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('attested_at')
                    ->label('When')
                    ->dateTime('Y-m-d H:i:s')
                    ->description(fn (BattleFcUserAttestation $r) => optional($r->attested_at)->diffForHumans())
                    ->sortable(),

                TextColumn::make('user_id')
                    ->label('User')
                    ->getStateUsing(function (BattleFcUserAttestation $r): string {
                        $u = self::userMap()[$r->user_id] ?? null;
                        return $u['name'] ?? ('user_' . $r->user_id);
                    })
                    ->description(fn (BattleFcUserAttestation $r) => self::userMap()[$r->user_id]['email'] ?? null)
                    ->sortable(),

                TextColumn::make('battle_id')
                    ->label('Battle')
                    ->url(fn (BattleFcUserAttestation $r) => '/portal/battles/' . $r->battle_id)
                    ->getStateUsing(fn (BattleFcUserAttestation $r) => self::battleMap()[$r->battle_id] ?? ('#' . $r->battle_id))
                    ->sortable(),

                TextColumn::make('alliance_id')
                    ->label('Alliance')
                    ->getStateUsing(fn (BattleFcUserAttestation $r) => self::allianceNameMap()[$r->alliance_id] ?? ('Alliance #' . $r->alliance_id))
                    ->toggleable(),

                TextColumn::make('sub_fleet_id')
                    ->label('SF')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('partition_algo_version')
                    ->label('v')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('attested_character_id')
                    ->label('Attested FC')
                    ->getStateUsing(fn (BattleFcUserAttestation $r) => self::characterNameMap()[$r->attested_character_id] ?? ('char_' . $r->attested_character_id))
                    ->searchable(query: function (Builder $q, string $s) {
                        $ids = DB::table('esi_entity_names')
                            ->where('category', 'character')
                            ->where('name', 'like', "%{$s}%")
                            ->pluck('entity_id');
                        $q->whereIn('attested_character_id', $ids);
                    }),

                TextColumn::make('user_note')
                    ->label('Note')
                    ->limit(40)
                    ->tooltip(fn (BattleFcUserAttestation $r) => $r->user_note)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('sub_fleet_id')
                    ->options(fn () => BattleFcUserAttestation::query()
                        ->distinct()
                        ->orderBy('sub_fleet_id')
                        ->pluck('sub_fleet_id', 'sub_fleet_id')
                        ->map(fn ($v) => "SF {$v}")
                        ->all()),
            ])
            ->recordActions([]);
    }

    /** @var array<int, array{name:string, email:string}>|null */
    private static ?array $userMapCache = null;

    private static function userMap(): array
    {
        if (self::$userMapCache === null) {
            self::$userMapCache = DB::table('users')
                ->select('id', 'name', 'email')
                ->get()
                ->mapWithKeys(fn ($u) => [(int) $u->id => ['name' => $u->name, 'email' => $u->email]])
                ->all();
        }
        return self::$userMapCache;
    }

    /** @var array<int, string>|null */
    private static ?array $battleMapCache = null;

    private static function battleMap(): array
    {
        if (self::$battleMapCache === null) {
            self::$battleMapCache = DB::table('battle_theaters')
                ->select('id', 'public_slug')
                ->get()
                ->mapWithKeys(fn ($b) => [(int) $b->id => $b->public_slug ?: ('#' . $b->id)])
                ->all();
        }
        return self::$battleMapCache;
    }

    /** @var array<int, string>|null */
    private static ?array $allianceNameCache = null;

    private static function allianceNameMap(): array
    {
        if (self::$allianceNameCache === null) {
            self::$allianceNameCache = DB::table('esi_entity_names')
                ->where('category', 'alliance')
                ->pluck('name', 'entity_id')
                ->all();
        }
        return self::$allianceNameCache;
    }

    /** @var array<int, string>|null */
    private static ?array $characterNameCache = null;

    private static function characterNameMap(): array
    {
        if (self::$characterNameCache === null) {
            self::$characterNameCache = DB::table('esi_entity_names')
                ->where('category', 'character')
                ->pluck('name', 'entity_id')
                ->all();
        }
        return self::$characterNameCache;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBattleFcAttestations::route('/'),
        ];
    }
}

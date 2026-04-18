<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/my-doctrines — shows the auto-detected, role-tied
 * doctrines of the current user's alliance. Scoped by
 * Auth::user()->characters()->first()->alliance_id so donors /
 * non-donors alike see only their own alliance's fits.
 *
 * Filter: only is_active=1 rows are shown (confidence >= 0.70 + past
 * the per-role observation floor). Sub-threshold clusters exist but
 * are hidden — see /admin for full listing.
 */
class MyDoctrines extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'My Doctrines';

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?int $navigationSort = 70;

    protected static ?string $title = 'My Alliance Doctrines';

    protected string $view = 'filament.portal.pages.my-doctrines';

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check();
    }

    public function getViewData(): array
    {
        $user = Auth::user();
        if ($user === null) {
            return ['doctrines' => [], 'corp_name' => null, 'corp_id' => null];
        }

        $corpId = (int) ($user->characters()->whereNotNull('corporation_id')->value('corporation_id') ?? 0);
        if ($corpId <= 0) {
            return ['doctrines' => [], 'corp_name' => null, 'corp_id' => null];
        }

        $corpName = DB::table('esi_entity_names')
            ->where('entity_id', $corpId)
            ->where('category', 'corporation')
            ->value('name') ?? ('Corp #' . $corpId);

        $rows = DB::table('auto_doctrines AS d')
            ->leftJoin('ref_item_types AS rit', 'rit.id', '=', 'd.hull_type_id')
            ->where('d.corporation_id', $corpId)
            ->where('d.is_active', 1)
            ->orderBy('d.role_key')
            ->orderByDesc('d.confidence')
            ->select(
                'd.id', 'd.hull_type_id', 'd.role_key', 'd.canonical_name',
                'd.observation_count', 'd.confidence', 'd.last_seen_at',
                'rit.name AS hull_name'
            )
            ->get();

        $ids = $rows->pluck('id')->all();
        $modules = DB::table('auto_doctrine_modules AS m')
            ->leftJoin('ref_item_types AS rit', 'rit.id', '=', 'm.type_id')
            ->whereIn('m.doctrine_id', $ids)
            ->select('m.doctrine_id', 'm.type_id', 'm.flag_category', 'm.quantity', 'm.frequency', 'rit.name AS mod_name')
            ->get()
            ->groupBy('doctrine_id');

        $doctrines = [];
        foreach ($rows as $r) {
            $doctrines[] = [
                'id' => $r->id,
                'role' => $r->role_key,
                'hull_type_id' => $r->hull_type_id,
                'hull_name' => $r->hull_name,
                'label' => $r->canonical_name,
                'n' => $r->observation_count,
                'confidence' => (float) $r->confidence,
                'last_seen' => $r->last_seen_at,
                'modules' => ($modules->get($r->id) ?? collect())
                    ->sortBy([['flag_category', 'asc'], ['mod_name', 'asc']])
                    ->map(fn ($m) => [
                        'type_id' => $m->type_id,
                        'name' => $m->mod_name,
                        'slot' => $m->flag_category,
                        'quantity' => $m->quantity,
                        'frequency' => (float) $m->frequency,
                    ])->values()->all(),
            ];
        }

        return [
            'doctrines' => $doctrines,
            'corp_id' => $corpId,
            'corp_name' => $corpName,
        ];
    }
}

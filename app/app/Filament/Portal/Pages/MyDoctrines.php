<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/my-doctrines — doctrines the viewer's corp has adopted.
 *
 * Doctrines are global (one canonical row per fit). Scoping via
 * auto_doctrine_adopters join filtered on viewer's corporation_id.
 * Only is_active=1 doctrines show up (confidence + floor gate).
 */
class MyDoctrines extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'My Doctrines';

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?int $navigationSort = 70;

    protected static ?string $title = 'My Corp Doctrines';

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
            ->join('auto_doctrine_adopters AS a', 'a.doctrine_id', '=', 'd.id')
            ->leftJoin('ref_item_types AS rit', 'rit.id', '=', 'd.hull_type_id')
            ->where('d.is_active', 1)
            ->where('a.corporation_id', $corpId)
            ->orderBy('d.role_key')
            ->orderByDesc('a.observation_count')
            ->select(
                'd.id', 'd.hull_type_id', 'd.role_key', 'd.canonical_name',
                'd.observation_count AS global_n',
                'd.confidence', 'd.last_seen_at',
                'a.observation_count AS corp_n',
                'a.last_seen_at AS corp_last_seen',
                'rit.name AS hull_name'
            )
            ->get();

        $ids = $rows->pluck('id')->all();

        // Global core modules (what ≥80% of ALL corps fielding the
        // doctrine have in common).
        $globalModules = DB::table('auto_doctrine_modules AS m')
            ->leftJoin('ref_item_types AS rit', 'rit.id', '=', 'm.type_id')
            ->whereIn('m.doctrine_id', $ids)
            ->select('m.doctrine_id', 'm.type_id', 'm.flag_category', 'm.quantity', 'm.frequency', 'rit.name AS mod_name')
            ->get()
            ->groupBy('doctrine_id');

        // This corp's own core modules per doctrine (≥60% cutoff, needs
        // ≥3 observations). Lets the view surface corp-specific
        // variations that diverge from the global core.
        $corpModules = DB::table('auto_doctrine_adopter_modules AS m')
            ->leftJoin('ref_item_types AS rit', 'rit.id', '=', 'm.type_id')
            ->whereIn('m.doctrine_id', $ids)
            ->where('m.corporation_id', $corpId)
            ->select('m.doctrine_id', 'm.type_id', 'm.flag_category', 'm.quantity', 'm.frequency', 'rit.name AS mod_name')
            ->get()
            ->groupBy('doctrine_id');

        $doctrines = [];
        foreach ($rows as $r) {
            $globals = $globalModules->get($r->id) ?? collect();
            $corps   = $corpModules->get($r->id) ?? collect();

            // Merge global + corp — render each module with "global"
            // and "your corp" flags so the blade can show a single
            // consolidated list without duplicates.
            $byKey = [];
            foreach ($globals as $m) {
                $k = "{$m->type_id}|{$m->flag_category}";
                $byKey[$k] = [
                    'type_id' => $m->type_id,
                    'name' => $m->mod_name,
                    'slot' => $m->flag_category,
                    'quantity' => (int) $m->quantity,
                    'global' => true,
                    'corp'   => false,
                    'global_freq' => (float) $m->frequency,
                    'corp_freq' => null,
                ];
            }
            foreach ($corps as $m) {
                $k = "{$m->type_id}|{$m->flag_category}";
                if (isset($byKey[$k])) {
                    $byKey[$k]['corp'] = true;
                    $byKey[$k]['corp_freq'] = (float) $m->frequency;
                    // Prefer the corp's quantity if it diverges; the
                    // corp number is what they actually fly.
                    $byKey[$k]['quantity'] = (int) $m->quantity;
                } else {
                    $byKey[$k] = [
                        'type_id' => $m->type_id,
                        'name' => $m->mod_name,
                        'slot' => $m->flag_category,
                        'quantity' => (int) $m->quantity,
                        'global' => false,
                        'corp'   => true,
                        'global_freq' => null,
                        'corp_freq' => (float) $m->frequency,
                    ];
                }
            }
            $merged = collect(array_values($byKey))
                ->sortBy([['slot', 'asc'], ['name', 'asc']])
                ->values()
                ->all();

            $doctrines[] = [
                'id' => $r->id,
                'role' => $r->role_key,
                'hull_type_id' => $r->hull_type_id,
                'hull_name' => $r->hull_name,
                'label' => $r->canonical_name,
                'corp_n' => (int) $r->corp_n,
                'global_n' => (int) $r->global_n,
                'confidence' => (float) $r->confidence,
                'corp_last_seen' => $r->corp_last_seen,
                'modules' => $merged,
                'has_corp_variant' => $corps->isNotEmpty(),
            ];
        }

        return [
            'doctrines' => $doctrines,
            'corp_id' => $corpId,
            'corp_name' => $corpName,
        ];
    }
}

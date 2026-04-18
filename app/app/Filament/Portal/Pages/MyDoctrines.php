<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/my-doctrines — three-tier doctrine view:
 *   - My Corp      (corp-scoped adopters)
 *   - My Alliance  (alliance-scoped adopters)
 *   - My Bloc      (bloc-scoped adopters via coalition_entity_labels)
 *
 * Each card shows global vs adopter-scope module deltas + an
 * EFT-format export block + buyall shopping list for copy-paste.
 */
class MyDoctrines extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'My Doctrines';
    protected static string|UnitEnum|null $navigationGroup = 'Account';
    protected static ?int $navigationSort = 70;
    protected static ?string $title = 'My Doctrines';
    protected string $view = 'filament.portal.pages.my-doctrines';

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check();
    }

    public function getViewData(): array
    {
        $user = Auth::user();
        if ($user === null) {
            return $this->empty(null, null, null);
        }
        $char = $user->characters()->first();
        if ($char === null) {
            return $this->empty(null, null, null);
        }

        $corpId = (int) ($char->corporation_id ?? 0);
        $allianceId = (int) ($char->alliance_id ?? 0);
        $blocId = null;
        if ($allianceId > 0) {
            $blocId = DB::table('coalition_entity_labels')
                ->where('entity_type', 'alliance')
                ->where('entity_id', $allianceId)
                ->where('is_active', 1)
                ->value('bloc_id');
            $blocId = $blocId ? (int) $blocId : null;
        }

        $corpName = $corpId > 0
            ? (DB::table('esi_entity_names')->where('entity_id', $corpId)->where('category', 'corporation')->value('name') ?? ('Corp #' . $corpId))
            : null;
        $allianceName = $allianceId > 0
            ? (DB::table('esi_entity_names')->where('entity_id', $allianceId)->where('category', 'alliance')->value('name') ?? ('Alliance #' . $allianceId))
            : null;
        $blocName = $blocId
            ? (DB::table('coalition_blocs')->where('id', $blocId)->value('display_name') ?? ('Bloc #' . $blocId))
            : null;

        return [
            'corp_id' => $corpId ?: null,
            'corp_name' => $corpName,
            'alliance_id' => $allianceId ?: null,
            'alliance_name' => $allianceName,
            'bloc_id' => $blocId,
            'bloc_name' => $blocName,
            'corp_doctrines' => $corpId ? $this->loadDoctrines('auto_doctrine_adopters', 'corporation_id', $corpId, $corpId) : [],
            'alliance_doctrines' => $allianceId ? $this->loadDoctrines('auto_doctrine_alliance_adopters', 'alliance_id', $allianceId, $corpId) : [],
            'bloc_doctrines' => $blocId ? $this->loadDoctrines('auto_doctrine_bloc_adopters', 'bloc_id', $blocId, $corpId) : [],
        ];
    }

    private function empty(?int $cid, ?int $aid, ?int $bid): array
    {
        return [
            'corp_id' => $cid, 'corp_name' => null,
            'alliance_id' => $aid, 'alliance_name' => null,
            'bloc_id' => $bid, 'bloc_name' => null,
            'corp_doctrines' => [], 'alliance_doctrines' => [], 'bloc_doctrines' => [],
        ];
    }

    private function loadDoctrines(string $table, string $scopeColumn, int $scopeId, int $viewerCorpId): array
    {
        $rows = DB::table("{$table} AS a")
            ->join('auto_doctrines AS d', 'd.id', '=', 'a.doctrine_id')
            ->leftJoin('ref_item_types AS rit', 'rit.id', '=', 'd.hull_type_id')
            ->where('d.is_active', 1)
            ->where("a.{$scopeColumn}", $scopeId)
            ->orderBy('d.role_key')
            ->orderByDesc('a.observation_count')
            ->select(
                'd.id', 'd.hull_type_id', 'd.role_key', 'd.canonical_name',
                'd.observation_count AS global_n',
                'd.confidence', 'd.last_seen_at',
                'a.observation_count AS scope_n',
                'a.last_seen_at AS scope_last_seen',
                'rit.name AS hull_name'
            )
            ->get();

        if ($rows->isEmpty()) return [];
        $ids = $rows->pluck('id')->all();

        $globalModules = DB::table('auto_doctrine_modules AS m')
            ->leftJoin('ref_item_types AS rit', 'rit.id', '=', 'm.type_id')
            ->whereIn('m.doctrine_id', $ids)
            ->select(
                'm.doctrine_id', 'm.type_id', 'm.canonical_type_id',
                'm.flag_category', 'm.quantity', 'm.frequency',
                'm.variants_json', 'rit.name AS mod_name'
            )
            ->get()
            ->groupBy('doctrine_id');

        $corpModules = collect();
        if ($viewerCorpId > 0) {
            $corpModules = DB::table('auto_doctrine_adopter_modules AS m')
                ->whereIn('m.doctrine_id', $ids)
                ->where('m.corporation_id', $viewerCorpId)
                ->select('m.doctrine_id', 'm.canonical_type_id', 'm.flag_category')
                ->get()
                ->groupBy('doctrine_id');
        }

        $out = [];
        foreach ($rows as $r) {
            $globals = $globalModules->get($r->id) ?? collect();
            $corps   = $corpModules->get($r->id) ?? collect();

            // Build a set of (canonical_type_id|slot) pairs the corp
            // fits, so the global row can be flagged as "corp also
            // fits this" without mismatching on meta variant.
            $corpKeys = [];
            foreach ($corps as $cm) {
                $corpKeys["{$cm->canonical_type_id}|{$cm->flag_category}"] = true;
            }

            $byKey = [];
            foreach ($globals as $m) {
                $canon = (int) $m->canonical_type_id;
                $k = "{$canon}|{$m->flag_category}";
                $variants = $m->variants_json ? json_decode($m->variants_json, true) : null;
                // variants_json ordered by count desc at compute time,
                // so the head is the most-common (== $m->type_id). Pull
                // the tail for "also seen" annotation.
                $alsoSeen = [];
                if (is_array($variants) && count($variants) > 1) {
                    foreach (array_slice($variants, 1) as $v) {
                        $alsoSeen[] = [
                            'type_id' => (int) $v['type_id'],
                            'name' => (string) ($v['name'] ?? ('type ' . $v['type_id'])),
                            'frequency' => (float) ($v['frequency'] ?? 0),
                        ];
                    }
                }
                $byKey[$k] = [
                    'type_id' => (int) $m->type_id,
                    'canonical_type_id' => $canon,
                    'name' => $m->mod_name,
                    'slot' => $m->flag_category,
                    'quantity' => (int) $m->quantity,
                    'global' => true,
                    'corp' => isset($corpKeys[$k]),
                    'also_seen' => $alsoSeen,
                ];
            }
            // Corp-only: corp fits something global doesn't. Fall back
            // to ref lookup for name.
            foreach ($corps as $cm) {
                $k = "{$cm->canonical_type_id}|{$cm->flag_category}";
                if (isset($byKey[$k])) continue;
                $name = DB::table('ref_item_types')->where('id', $cm->canonical_type_id)->value('name')
                    ?? ('type ' . $cm->canonical_type_id);
                $byKey[$k] = [
                    'type_id' => (int) $cm->canonical_type_id,
                    'canonical_type_id' => (int) $cm->canonical_type_id,
                    'name' => $name,
                    'slot' => $cm->flag_category,
                    'quantity' => 1,
                    'global' => false, 'corp' => true,
                    'also_seen' => [],
                ];
            }

            $modules = collect(array_values($byKey))
                ->sortBy([['slot', 'asc'], ['name', 'asc']])
                ->values()
                ->all();

            $out[] = [
                'id' => $r->id,
                'role' => $r->role_key,
                'hull_type_id' => (int) $r->hull_type_id,
                'hull_name' => $r->hull_name,
                'label' => $r->canonical_name,
                'scope_n' => (int) $r->scope_n,
                'global_n' => (int) $r->global_n,
                'confidence' => (float) $r->confidence,
                'modules' => $modules,
                'has_corp_variant' => $corps->isNotEmpty(),
                'eft' => $this->toEft($r->hull_name ?? 'Unknown', $modules),
                'buyall' => $this->toBuyall($modules),
            ];
        }
        return $out;
    }

    private function toEft(string $hullName, array $modules): string
    {
        $order = ['low', 'mid', 'high', 'rig', 'subsystem'];
        $bySlot = [];
        foreach ($modules as $m) {
            $bySlot[$m['slot']][] = $m;
        }
        $lines = ["[{$hullName}, AegisCore auto-doctrine]"];
        foreach ($order as $slot) {
            $rows = $bySlot[$slot] ?? [];
            if ($rows === []) { $lines[] = ''; continue; }
            $blk = [];
            foreach ($rows as $m) {
                $name = $m['name'] ?? ('type ' . $m['type_id']);
                for ($i = 0; $i < max(1, $m['quantity']); $i++) $blk[] = $name;
            }
            $lines[] = implode("\n", $blk);
            $lines[] = '';
        }
        return rtrim(implode("\n", $lines));
    }

    private function toBuyall(array $modules): string
    {
        $lines = [];
        foreach ($modules as $m) {
            $name = $m['name'] ?? ('type ' . $m['type_id']);
            $q = max(1, $m['quantity']);
            $lines[] = "{$q} {$name}";
        }
        return implode("\n", $lines);
    }
}

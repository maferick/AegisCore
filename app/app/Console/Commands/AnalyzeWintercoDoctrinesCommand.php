<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot: parse ground-truth WinterCo fit HTMLs (/root/doctrine/*.html),
 * canonicalize the same way ComputeAutoDoctrinesCommand does, and match
 * each truth fit against the computed auto_doctrines catalog.
 *
 * Output: coverage summary (hit as primary / hit as tail / missing) plus
 * module-level diffs for near-misses so we can see whether our fingerprint
 * is under- or over-splitting.
 */
class AnalyzeWintercoDoctrinesCommand extends Command
{
    protected $signature = 'doctrines:analyze-winterco
                            {--dir=/root/doctrine}
                            {--jaccard=0.65}
                            {--csv=}';

    protected $description = 'Cross-reference WinterCo ground-truth fits against auto_doctrines.';

    /** @var array<int,array{canonical:int,name:string}> */
    private array $canonMap = [];

    /** @var array<string,int> lowercase name → type_id */
    private array $nameIndex = [];

    public function handle(): int
    {
        $dir = rtrim((string) $this->option('dir'), '/');
        $jaccard = (float) $this->option('jaccard');
        $csvPath = $this->option('csv');

        $files = glob($dir . '/*.html') ?: [];
        $this->info(sprintf('Ground-truth fit files: %d', count($files)));
        if ($files === []) return self::SUCCESS;

        $this->loadRefIndex();

        $truth = [];
        foreach ($files as $f) {
            $eft = $this->extractEft(file_get_contents($f) ?: '');
            if ($eft === null) {
                $this->warn('No EFT in ' . basename($f));
                continue;
            }
            $parsed = $this->parseEft($eft);
            if ($parsed === null) {
                $this->warn('Parse fail ' . basename($f));
                continue;
            }
            [$hullTypeId, $hullName, $label, $modules] = $parsed;
            if ($hullTypeId === 0) {
                $this->warn(sprintf('Hull "%s" not found (%s)', $hullName, basename($f)));
                continue;
            }
            $canonicalModules = [];
            $unresolved = [];
            foreach ($modules as [$name, $slot]) {
                $tid = $this->resolveName($name);
                if ($tid === 0) {
                    $unresolved[] = $name;
                    continue;
                }
                $canon = $this->canonMap[$tid]['canonical'] ?? $tid;
                $canonicalModules[] = [$canon, $slot];
            }
            if ($canonicalModules === []) continue;
            $fp = $this->fingerprint($canonicalModules);
            $truth[] = [
                'file' => basename($f),
                'hull_type_id' => $hullTypeId,
                'hull_name' => $hullName,
                'label' => $label,
                'fp' => $fp,
                'modules' => $canonicalModules,
                'unresolved' => $unresolved,
            ];
        }

        $this->info(sprintf('Parsed truth fits: %d', count($truth)));

        $hulls = array_unique(array_column($truth, 'hull_type_id'));
        $autoByHull = DB::table('auto_doctrines')
            ->whereIn('hull_type_id', $hulls)
            ->where('is_active', 1)
            ->orderByDesc('observation_count')
            ->get()
            ->groupBy('hull_type_id');

        $autoModules = DB::table('auto_doctrine_modules')
            ->whereIn('doctrine_id', $autoByHull->flatten(1)->pluck('id')->all())
            ->get()
            ->groupBy('doctrine_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => [(int) $r->canonical_type_id, (string) $r->flag_category, (int) $r->quantity])->all())
            ->all();

        $rows = [];
        $counts = ['exact' => 0, 'fuzzy' => 0, 'hull_only' => 0, 'no_hull' => 0];
        foreach ($truth as $t) {
            $hull = $t['hull_type_id'];
            $candidates = $autoByHull->get($hull, collect());
            if ($candidates->isEmpty()) {
                $counts['no_hull']++;
                $rows[] = [$t['label'], $t['hull_name'], 'NO AUTO DOCTRINE FOR HULL', 0, 0.0, ''];
                continue;
            }
            $exact = $candidates->firstWhere('canonical_fingerprint', $t['fp']);
            if ($exact) {
                $counts['exact']++;
                $rows[] = [$t['label'], $t['hull_name'], 'EXACT', (int) $exact->observation_count, round((float) $exact->confidence, 2), (string) $exact->id];
                continue;
            }
            // Fuzzy: find best jaccard match among hull's auto doctrines.
            $truthSet = $this->expandMultiset($t['modules']);
            $bestSim = 0.0;
            $bestRow = null;
            foreach ($candidates as $c) {
                $mods = $autoModules[$c->id] ?? [];
                $autoSet = [];
                foreach ($mods as [$tid, $slot, $q]) {
                    for ($i = 0; $i < $q; $i++) $autoSet[] = "{$tid}|{$slot}";
                }
                sort($autoSet);
                $sim = $this->jaccardMultiset($truthSet, $autoSet);
                if ($sim > $bestSim) { $bestSim = $sim; $bestRow = $c; }
            }
            if ($bestSim >= $jaccard && $bestRow !== null) {
                $counts['fuzzy']++;
                $rows[] = [$t['label'], $t['hull_name'], sprintf('FUZZY %.2f', $bestSim), (int) $bestRow->observation_count, round((float) $bestRow->confidence, 2), (string) $bestRow->id];
            } else {
                $counts['hull_only']++;
                $rows[] = [$t['label'], $t['hull_name'], sprintf('HULL ONLY (best %.2f)', $bestSim), $bestRow ? (int) $bestRow->observation_count : 0, $bestRow ? round((float) $bestRow->confidence, 2) : 0.0, $bestRow?->id ?? ''];
            }
        }

        $this->line('');
        $this->info('Coverage:');
        $this->table(['bucket', 'n'], array_map(fn ($k, $v) => [$k, $v], array_keys($counts), array_values($counts)));

        $this->line('');
        $this->info('Per-fit:');
        $this->table(['label', 'hull', 'match', 'global_n', 'conf', 'doctrine_id'], $rows);

        if ($csvPath) {
            $fh = fopen($csvPath, 'w');
            fputcsv($fh, ['label', 'hull', 'match', 'global_n', 'conf', 'doctrine_id']);
            foreach ($rows as $r) fputcsv($fh, $r);
            fclose($fh);
            $this->info("CSV written: $csvPath");
        }

        return self::SUCCESS;
    }

    private function loadRefIndex(): void
    {
        $this->canonMap = [];
        $this->nameIndex = [];
        foreach (DB::table('ref_item_types')->select('id', 'name', 'variation_parent_type_id')->cursor() as $row) {
            $id = (int) $row->id;
            $this->canonMap[$id] = [
                'canonical' => $row->variation_parent_type_id !== null ? (int) $row->variation_parent_type_id : $id,
                'name' => (string) $row->name,
            ];
            $this->nameIndex[mb_strtolower(trim((string) $row->name))] = $id;
        }
        $this->info(sprintf('Ref index: %d types', count($this->canonMap)));
    }

    private function extractEft(string $html): ?string
    {
        if (! preg_match('/<textarea[^>]*>(\[[^\]]+\][^<]*?)<\/textarea>/s', $html, $m)) return null;
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @return array{0:int,1:string,2:string,3:list<array{0:string,1:string}>}|null
     */
    private function parseEft(string $eft): ?array
    {
        $eft = trim($eft);
        // Normalise line endings.
        $eft = preg_replace("/\r\n?/", "\n", $eft);
        $lines = explode("\n", $eft);
        if (! preg_match('/^\[([^,]+),\s*(.+?)\]\s*$/', $lines[0], $m)) return null;
        $hullName = trim($m[1]);
        $label = trim($m[2]);
        $hullTypeId = $this->resolveName($hullName);

        // Split by blank lines into blocks; slot order: low, mid, high, rig, subsystem.
        $blocks = [];
        $cur = [];
        foreach (array_slice($lines, 1) as $ln) {
            if (trim($ln) === '') {
                if ($cur !== []) { $blocks[] = $cur; $cur = []; }
                continue;
            }
            $cur[] = $ln;
        }
        if ($cur !== []) $blocks[] = $cur;

        $slotOrder = ['low', 'mid', 'high', 'rig', 'subsystem'];
        $modules = [];
        foreach ($slotOrder as $i => $slot) {
            $block = $blocks[$i] ?? [];
            foreach ($block as $ln) {
                $name = trim($ln);
                if ($name === '') continue;
                // "Module Name, Charge Name" — charge is ammo, drop it.
                $name = explode(',', $name)[0];
                // "Item Name x900" — cargo / charges, skip (shouldn't appear in slot blocks but safeguard).
                if (preg_match('/\sx\d+$/', $name)) continue;
                $modules[] = [trim($name), $slot];
            }
        }

        return [$hullTypeId, $hullName, $label, $modules];
    }

    private function resolveName(string $name): int
    {
        return $this->nameIndex[mb_strtolower(trim($name))] ?? 0;
    }

    /**
     * @param list<array{0:int,1:string}> $modules
     */
    private function fingerprint(array $modules): string
    {
        $c = $modules;
        sort($c);
        return md5(json_encode($c));
    }

    /**
     * @param list<array{0:int,1:string}> $multiset
     * @return list<string>
     */
    private function expandMultiset(array $multiset): array
    {
        $out = [];
        foreach ($multiset as [$tid, $slot]) $out[] = "{$tid}|{$slot}";
        sort($out);
        return $out;
    }

    /**
     * Jaccard on multiset-as-sorted-list: treat each instance as unique token.
     * @param list<string> $a
     * @param list<string> $b
     */
    private function jaccardMultiset(array $a, array $b): float
    {
        $ca = array_count_values($a);
        $cb = array_count_values($b);
        $inter = 0; $union = 0;
        foreach (array_unique(array_merge(array_keys($ca), array_keys($cb))) as $k) {
            $ai = $ca[$k] ?? 0; $bi = $cb[$k] ?? 0;
            $inter += min($ai, $bi);
            $union += max($ai, $bi);
        }
        return $union === 0 ? 0.0 : $inter / $union;
    }
}

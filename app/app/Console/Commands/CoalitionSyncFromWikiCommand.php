<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Eve\Esi\EsiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * coalition:sync-from-wiki — refresh `coalition_entity_labels` from
 * authoritative coalition wikis.
 *
 * Sources (each is a wiki published by the coalition itself; treat
 * the wiki as the truth surface for "which alliances belong to this
 * bloc right now"):
 *
 *   - Imperium       https://wiki.goonswarm.org/w/The_Imperium                 → bloc id 3 (cfc)
 *   - Winter Coalition https://wiki.winterco.org/_export/raw/...                → bloc id 1 (winterco)
 *
 * For every alliance found:
 *   - resolve name → alliance_id via esi_entity_names lookup, fall
 *     back to ESI POST /universe/ids/ when unknown
 *   - upsert a coalition_entity_labels row with source='wiki:<bloc>'
 *
 * For every alliance previously sourced from wiki:<bloc> but not in
 * the current scrape:
 *   - flip is_active=0 (soft-delete; preserves audit history)
 *
 * Alliances NOT in any wiki source remain whatever the seed/manual
 * tag says. The "internal matching system" (manual seed labels +
 * Filament admin) keeps owning anything the wikis don't cover.
 */
class CoalitionSyncFromWikiCommand extends Command
{
    protected $signature = 'coalition:sync-from-wiki
        {--source=all : all | imperium | winterco}
        {--dry-run : report what would change without writing}';

    protected $description = 'Sync coalition_entity_labels from authoritative bloc wikis (Imperium, Winter Coalition).';

    private const SOURCES = [
        'imperium' => [
            'bloc_id' => 3,
            'bloc_code' => 'cfc',
            'url' => 'https://wiki.goonswarm.org/w/The_Imperium',
            'wiki_source' => 'wiki:imperium',
            'parser' => 'parseImperium',
        ],
        'winterco' => [
            'bloc_id' => 1,
            'bloc_code' => 'winterco',
            'url' => 'https://wiki.winterco.org/_export/raw/en/guide/coalition/winter_coalition_member_and_allied_alliances',
            'wiki_source' => 'wiki:winterco',
            'parser' => 'parseWinterCo',
        ],
    ];

    public function handle(EsiClient $esi): int
    {
        $sourceFilter = (string) $this->option('source');
        $dryRun = (bool) $this->option('dry-run');

        $sources = $sourceFilter === 'all'
            ? array_keys(self::SOURCES)
            : [$sourceFilter];

        $totalUpserts = 0;
        $totalDeactivated = 0;
        $unresolved = [];

        foreach ($sources as $key) {
            if (! isset(self::SOURCES[$key])) {
                $this->error("unknown source: {$key}");
                return self::FAILURE;
            }
            $cfg = self::SOURCES[$key];
            $this->info("=== {$key} → bloc_id={$cfg['bloc_id']} ({$cfg['bloc_code']}) ===");
            $this->info("fetching {$cfg['url']}");

            $body = $this->fetch($cfg['url']);
            if ($body === null) {
                $this->warn("fetch failed; skipping {$key}");
                continue;
            }

            $alliances = $this->{$cfg['parser']}($body);
            if ($alliances === []) {
                $this->warn("parser returned 0 alliances; skipping {$key} to avoid wiping labels on a parser drift");
                continue;
            }
            $this->info("parsed " . count($alliances) . " alliance entries");

            // Resolve names → (entity_type, id). Wiki tables sometimes
            // list corporations alongside alliances; we accept both so
            // the wiki stays the source of truth even when the listing
            // hasn't been kept rigorously alliance-only.
            $nameToEntity = $this->resolveEntityIds($esi, array_column($alliances, 'name'));
            $resolved = [];
            foreach ($alliances as $a) {
                $info = $nameToEntity[mb_strtolower($a['name'])] ?? null;
                if ($info === null) {
                    $unresolved[$key][] = $a['name'];
                    continue;
                }
                $resolved[$info['type'] . ':' . $info['id']] = $a + [
                    'entity_id' => $info['id'],
                    'entity_type' => $info['type'],
                    'resolved_name' => $info['name'],
                ];
            }
            $this->info("resolved " . count($resolved) . " entity ids; " . count($unresolved[$key] ?? []) . " unresolved");

            $upserts = 0;
            $deactivated = 0;
            $changes = [];

            foreach ($resolved as $a) {
                $entityType = $a['entity_type'];
                $entityId = (int) $a['entity_id'];
                $rawLabel = $cfg['bloc_code'] . '.' . ($a['kind'] ?? 'member');
                $existing = DB::table('coalition_entity_labels')
                    ->where('entity_type', $entityType)
                    ->where('entity_id', $entityId)
                    ->where('source', $cfg['wiki_source'])
                    ->first();

                $payload = [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'entity_name' => $a['resolved_name'] ?? $a['name'],
                    'raw_label' => $rawLabel,
                    'bloc_id' => $cfg['bloc_id'],
                    'relationship_type_id' => 1,
                    'source' => $cfg['wiki_source'],
                    'is_active' => 1,
                    'updated_at' => now(),
                ];

                if (! $existing) {
                    $payload['created_at'] = now();
                    if (! $dryRun) {
                        DB::table('coalition_entity_labels')->insert($payload);
                    }
                    $changes[] = ['action' => 'add', 'name' => $a['name'], 'eid' => $entityId, 'etype' => $entityType, 'raw_label' => $rawLabel];
                    $upserts++;
                } else {
                    $diff = [];
                    if ((int) $existing->bloc_id !== $cfg['bloc_id']) {
                        $diff['bloc_id'] = [(int) $existing->bloc_id, $cfg['bloc_id']];
                    }
                    if ($existing->raw_label !== $rawLabel) {
                        $diff['raw_label'] = [$existing->raw_label, $rawLabel];
                    }
                    if (! $existing->is_active) {
                        $diff['is_active'] = [false, true];
                    }
                    if ($diff !== []) {
                        if (! $dryRun) {
                            DB::table('coalition_entity_labels')
                                ->where('id', $existing->id)
                                ->update($payload);
                        }
                        $changes[] = ['action' => 'update', 'name' => $a['name'], 'eid' => $entityId, 'etype' => $entityType, 'diff' => $diff];
                        $upserts++;
                    }
                }
            }

            // Soft-delete entries previously sourced from this wiki but
            // no longer in the current scrape.
            $currentKeys = array_keys($resolved); // 'type:id'
            $existingActive = DB::table('coalition_entity_labels')
                ->where('source', $cfg['wiki_source'])
                ->where('is_active', 1)
                ->get(['id', 'entity_type', 'entity_id', 'entity_name']);
            foreach ($existingActive as $row) {
                $rowKey = $row->entity_type . ':' . $row->entity_id;
                if (! in_array($rowKey, $currentKeys, true)) {
                    if (! $dryRun) {
                        DB::table('coalition_entity_labels')
                            ->where('id', $row->id)
                            ->update(['is_active' => 0, 'updated_at' => now()]);
                    }
                    $changes[] = ['action' => 'deactivate', 'name' => $row->entity_name, 'eid' => (int) $row->entity_id, 'etype' => $row->entity_type];
                    $deactivated++;
                }
            }

            $this->info("changes: {$upserts} upserts, {$deactivated} deactivated" . ($dryRun ? ' (dry-run)' : ''));
            foreach ($changes as $c) {
                $line = "  [{$c['action']}] {$c['name']} ({$c['etype']} #{$c['eid']})";
                if (isset($c['raw_label'])) $line .= " label={$c['raw_label']}";
                if (isset($c['diff'])) $line .= ' diff=' . json_encode($c['diff']);
                $this->line($line);
            }
            $totalUpserts += $upserts;
            $totalDeactivated += $deactivated;
        }

        $this->newLine();
        $this->info("total: {$totalUpserts} upserts, {$totalDeactivated} deactivated");
        foreach ($unresolved as $src => $names) {
            $this->warn("unresolved alliance names from {$src}: " . count($names));
            foreach ($names as $n) {
                $this->line("  - {$n}");
            }
        }
        return self::SUCCESS;
    }

    private function fetch(string $url): ?string
    {
        try {
            $resp = Http::withUserAgent('AegisCore/1.0 (+coalition-sync)')
                ->timeout(30)
                ->get($url);
            if (! $resp->successful()) {
                $this->warn("fetch returned HTTP {$resp->status()}");
                return null;
            }
            return $resp->body();
        } catch (\Throwable $e) {
            $this->error("fetch threw: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Goonswarm wiki uses MediaWiki — Members section starts with
     *
     *   <h3 id="Members">Members</h3></div><section ...>
     *
     * and contains a single <ul><li>Alliance Name</li>...</ul>. Stop
     * at the next mw-heading div so we don't pick up the historical
     * timeline that follows.
     *
     * @return list<array{name:string, kind:string}>
     */
    private function parseImperium(string $html): array
    {
        if (! preg_match('/<h3 id="Members">.*?<\/h3>(.*?)<div class="mw-heading/su', $html, $m)) {
            $this->warn('imperium parser: Members section not found');
            return [];
        }
        if (! preg_match('/<ul>(.*?)<\/ul>/su', $m[1], $um)) {
            return [];
        }
        $out = [];
        if (preg_match_all('/<li>(.*?)<\/li>/su', $um[1], $items)) {
            foreach ($items[1] as $raw) {
                $name = trim(strip_tags($raw));
                $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($name === '' || mb_strlen($name) > 80) continue;
                $out[] = ['name' => $name, 'kind' => 'member'];
            }
        }
        return $out;
    }

    /**
     * WinterCo uses DokuWiki. Raw export contains:
     *
     *   ===== Member Alliances =====
     *   ^ --- Main Alliance --- ^ --- Ticker --- ^ --- Contacts ---^
     *   | Apocalypse Now. | APOC | JedsZero |
     *   ...
     *
     *   ===== Associated Alliances =====
     *   ...
     *
     * Members and associates are both surfaced; associates carry
     * kind='associate' so the raw_label distinguishes them.
     *
     * @return list<array{name:string, kind:string}>
     */
    private function parseWinterCo(string $raw): array
    {
        // The DokuWiki page puts BOTH the main and associated tables
        // under a single `===== Member Alliances =====` heading. Each
        // table starts with a `^ --- <name> --- ^ ...` header row that
        // doubles as a section divider — "Main Alliance", "Associated
        // Alliances". Track the current kind by watching for those
        // header rows; rows starting with `|` between them are
        // alliance entries.
        $lines = preg_split('/\r?\n/u', $raw);
        $inMemberSection = false;
        $kind = null; // 'member' | 'associate'
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // Detect main heading enter.
            if (preg_match('/^=====\s*Member Alliances\s*=====/u', $line)) {
                $inMemberSection = true;
                $kind = null;
                continue;
            }
            if (! $inMemberSection) continue;
            // Any other ===== heading ends the section.
            if (preg_match('/^=====[^=]+=====/u', $line)) {
                $inMemberSection = false;
                continue;
            }
            // Table-header rows start with `^` and indicate which
            // sub-table follows.
            if (str_starts_with($line, '^')) {
                $head = mb_strtolower($line);
                if (str_contains($head, 'associated')) {
                    $kind = 'associate';
                } elseif (str_contains($head, 'main alliance')) {
                    $kind = 'member';
                }
                continue;
            }
            if (! str_starts_with($line, '|') || $kind === null) continue;
            $cols = array_map('trim', explode('|', $line));
            // Leading `|` produces an empty first column; the alliance
            // name is the second column.
            $name = $cols[1] ?? '';
            if ($name === '' || str_contains($name, '---')) continue;
            $out[] = ['name' => $name, 'kind' => $kind];
        }
        return $out;
    }

    /**
     * Resolve names to (entity_type, entity_id, canonical_name).
     * Wiki tables sometimes list corporations alongside alliances —
     * accept both, ignore characters/types.
     *
     * @param  list<string>  $names
     * @return array<string, array{type:string,id:int,name:string}>
     */
    private function resolveEntityIds(EsiClient $esi, array $names): array
    {
        $names = array_values(array_unique(array_map(fn ($n) => trim($n), $names)));
        if ($names === []) return [];

        // First pass — DB lookup against esi_entity_names (alliance OR
        // corporation rows). Lowercase normalised key.
        $lcNames = array_map(fn ($n) => mb_strtolower($n), $names);
        $rows = DB::table('esi_entity_names')
            ->whereIn('category', ['alliance', 'corporation'])
            ->whereIn(DB::raw('LOWER(name)'), $lcNames)
            ->get(['entity_id', 'name', 'category']);
        $map = [];
        foreach ($rows as $r) {
            // Alliance wins over corporation when both exist for the
            // same name.
            $key = mb_strtolower((string) $r->name);
            if (! isset($map[$key]) || $r->category === 'alliance') {
                $map[$key] = [
                    'type' => (string) $r->category,
                    'id' => (int) $r->entity_id,
                    'name' => (string) $r->name,
                ];
            }
        }

        $unresolved = array_values(array_filter($names, fn ($n) => ! isset($map[mb_strtolower($n)])));
        if ($unresolved === []) return $map;

        $this->info('querying ESI /universe/ids/ for ' . count($unresolved) . ' unresolved names');
        try {
            $resp = $esi->post('/universe/ids/', $unresolved);
            $payload = $resp->data ?? null;
            if (is_array($payload)) {
                foreach (['alliances' => 'alliance', 'corporations' => 'corporation'] as $key => $type) {
                    if (! isset($payload[$key]) || ! is_array($payload[$key])) continue;
                    foreach ($payload[$key] as $row) {
                        $name = (string) ($row['name'] ?? '');
                        $id = (int) ($row['id'] ?? 0);
                        if (! $name || ! $id) continue;
                        $lc = mb_strtolower($name);
                        if (! isset($map[$lc]) || $type === 'alliance') {
                            $map[$lc] = ['type' => $type, 'id' => $id, 'name' => $name];
                        }
                        DB::table('esi_entity_names')->updateOrInsert(
                            ['entity_id' => $id, 'category' => $type],
                            ['name' => $name, 'updated_at' => now(), 'created_at' => now()],
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->warn("ESI /universe/ids/ failed: {$e->getMessage()}");
        }
        return $map;
    }
}

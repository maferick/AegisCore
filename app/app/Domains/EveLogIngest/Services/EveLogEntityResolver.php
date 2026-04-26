<?php

declare(strict_types=1);

namespace App\Domains\EveLogIngest\Services;

use App\Domains\EveLogIngest\Services\EveLogParser;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.2A — token-to-entity resolver for parsed log messages.
 *
 * Given the body of an intel / chat / fleet message, return a list
 * of resolutions:
 *
 *   [
 *     ['type' => 'system',    'id' => 30000238, 'name' => 'WBR5-R',
 *      'token' => 'WBR5-R', 'method' => 'system_code', 'confidence' => 'high'],
 *     ['type' => 'character', 'id' => 9100..., 'name' => 'Sid Matthews',
 *      'token' => 'Sid Matthews', 'method' => 'exact_name', 'confidence' => 'high'],
 *     ...
 *   ]
 *
 * Implementation notes:
 *
 *   1. EVE system names are alphanumeric+hyphen (e.g. WBR5-R,
 *      G9D-XW, J123456). Caught via regex first; lookup against
 *      ref_solar_systems.name with an in-memory cache.
 *
 *   2. Character / corp / alliance names need multi-word matching.
 *      Greedy: try the longest token window first (3 words), shrink
 *      to 1, lookup against esi_entity_names. Stop at first match
 *      so "Sid Matthews vedmak" doesn't double-count "Matthews"
 *      after "Sid Matthews" already resolved.
 *
 *   3. Stopwords skipped — common chat verbs, ship class words,
 *      directional markers — to keep noise out.
 *
 *   4. Resolutions are idempotent — uniqueness on (event_id, token,
 *      type) means re-resolving an event upserts cleanly.
 *
 * Costs scale linearly with message token count. For a ~5-token
 * intel line, ~3 SQL lookups per call. In-memory caches dedupe
 * across a batch.
 */
final class EveLogEntityResolver
{
    private const SYSTEM_CODE_REGEX = '/^[A-Z0-9]{1,4}(?:-[A-Z0-9]{1,4})+$|^J\d{6}$/';

    /**
     * Channel-/intel-chat tokens that look like names but never are.
     * Lowercased substring match for skip.
     */
    private const STOPWORDS = [
        'omw','warp','gate','station','dock','undock','ic','ico','red','blue',
        'go','back','to','from','out','off','in','on','at','the','and','or',
        'fleet','x','xup','wtb','wts','o7','o/','gf','gg','wp','sup','hi',
        'safe','safely','ok','tysm','ty','thx','plz','please','tnx','danke',
        'reds','red','blues','blue','neut','neuts','neutral','sorry',
        'gate','jb','bridge','jbg','sd','hold','hic','dic','warp',
        'cyno','jump','tackled','hold','target','primary','secondary','overheating',
        'fc','xo','co','ceo','dir','director','fcs','linkboost','links','linksabh',
        'fits','fit','doc','docked','undocked','need','want','have','more','less',
        'all','any','none','no','yes','maybe','wait','waiting','soon','fast','slow',
    ];

    /** @var array<string, array{0:int,1:string}|null> */
    private array $systemCache = [];

    /** @var array<string, array{0:int,1:string,2:string}|null>  key = lowercased name */
    private array $entityCache = [];

    /**
     * Resolve a message body. Optionally accepts the parsed_json
     * blob from the parser so already-extracted authoritative
     * showinfo links short-circuit the heuristic path with high
     * confidence rows.
     *
     * @param  array<string,mixed>|null  $parsedHints
     * @return list<array<string, mixed>>
     */
    public function resolve(string $message, ?array $parsedHints = null): array
    {
        $msg = trim($message);
        if ($msg === '') return [];

        $resolutions = [];

        // Pass 0 — authoritative showinfo links from the parser.
        // confidence='high', source='showinfo_link', the label is the
        // token. No fuzzy lookup needed: the entity_id is canonical.
        if (is_array($parsedHints) && ! empty($parsedHints['showinfo'])) {
            foreach ($parsedHints['showinfo'] as $idx => $link) {
                $typeId = (int) ($link['type_id'] ?? 0);
                $entityId = (int) ($link['entity_id'] ?? 0);
                $label = (string) ($link['label'] ?? '');
                if ($typeId === 0 || $entityId === 0) continue;
                $entityType = EveLogParser::showinfoTypeToEntityType($typeId);
                $resolvedName = $label;
                // For systems, replace the label with the canonical
                // ref_solar_systems.name to keep names consistent.
                if ($entityType === 'system') {
                    $sys = $this->lookupSystemById($entityId);
                    if ($sys !== null) $resolvedName = $sys;
                } elseif (in_array($entityType, ['character', 'corporation', 'alliance'], true)) {
                    $canon = $this->lookupEntityById($entityId);
                    if ($canon !== null) $resolvedName = $canon;
                }
                $resolutions[] = [
                    'token' => $label !== '' ? $label : "showinfo:{$typeId}/{$entityId}",
                    'type' => $entityType,
                    'id' => $entityId,
                    'name' => $resolvedName,
                    'method' => 'showinfo_link',
                    'source' => 'showinfo_link',
                    'showinfo_type_id' => $typeId,
                    'confidence' => 'high',
                    'token_offset' => $idx,
                ];
            }
            // If the parser already gave us links, we still run the
            // text-match path on the residual message (some intel lines
            // mix showinfo links with bare text names + system codes).
        }

        // Strip showinfo url tags from the residual text so the
        // tokeniser doesn't try to fuzzy-match against the labels we
        // already resolved authoritatively. Same for dscan urls.
        $residual = preg_replace('/<url=showinfo:\\d+\\/\\/\\d+>.*?<\\/url>/iu', ' ', $msg) ?? $msg;
        $residual = preg_replace('/https?:\\/\\/dscan\\.info\\/[a-z0-9\\/]+/iu', ' ', $residual) ?? $residual;

        // Tokenise. Split on whitespace + common chat punctuation.
        // Preserve hyphens inside system codes by NOT splitting on `-`.
        $rawTokens = preg_split('/[\s,;.:!?\\\\\\/`()\\[\\]{}<>"\'’“”]+/u', $residual) ?: [];
        $tokens = array_values(array_filter($rawTokens, fn ($t) => $t !== ''));
        if ($tokens === []) return $resolutions;

        $consumed = []; // token indexes consumed by a multi-word match

        // Pass 1: system codes. Anchored regex match; cheap.
        foreach ($tokens as $i => $tok) {
            if (isset($consumed[$i])) continue;
            if (! preg_match(self::SYSTEM_CODE_REGEX, $tok)) continue;
            $sys = $this->lookupSystem($tok);
            if ($sys === null) continue;
            $resolutions[] = [
                'token' => $tok,
                'type' => 'system',
                'id' => $sys[0],
                'name' => $sys[1],
                'method' => 'system_code',
                'confidence' => 'high',
                'token_offset' => $i,
            ];
            $consumed[$i] = true;
        }

        // Pass 2: multi-word entity names. Greedy, longest-first,
        // ranges 3 → 1.
        for ($len = 3; $len >= 1; $len--) {
            for ($i = 0; $i + $len <= count($tokens); $i++) {
                // Skip if any token in window already consumed.
                $skip = false;
                for ($j = $i; $j < $i + $len; $j++) {
                    if (isset($consumed[$j])) { $skip = true; break; }
                }
                if ($skip) continue;

                $window = array_slice($tokens, $i, $len);
                $candidate = implode(' ', $window);
                if ($this->isStopword($candidate)) continue;
                if ($len === 1 && mb_strlen($candidate) < 3) continue;
                if ($len === 1 && ctype_digit($candidate)) continue;

                $entity = $this->lookupEntity($candidate);
                if ($entity === null) continue;
                [$entId, $entName, $entCategory] = $entity;
                $resolutions[] = [
                    'token' => $candidate,
                    'type' => $entCategory,
                    'id' => $entId,
                    'name' => $entName,
                    'method' => 'exact_name',
                    'confidence' => $len >= 2 ? 'high' : 'medium',
                    'token_offset' => $i,
                ];
                for ($j = $i; $j < $i + $len; $j++) $consumed[$j] = true;
            }
        }

        return $resolutions;
    }

    /**
     * Persist a list of resolutions for an event id. Idempotent —
     * re-running the resolver on the same event updates existing rows
     * via the unique key (event, token, type).
     *
     * @param  list<array<string, mixed>>  $resolutions
     */
    public function persist(int $eveLogEventId, array $resolutions): int
    {
        if ($resolutions === []) return 0;
        $rows = [];
        $now = now();
        foreach ($resolutions as $r) {
            $rows[] = [
                'eve_log_event_id' => $eveLogEventId,
                'token' => mb_substr((string) $r['token'], 0, 120),
                'resolved_entity_type' => (string) $r['type'],
                'resolved_entity_id' => $r['id'] ?? null,
                'resolved_entity_name' => $r['name'] ? mb_substr((string) $r['name'], 0, 150) : null,
                'resolution_confidence' => (string) ($r['confidence'] ?? 'low'),
                'resolution_method' => (string) ($r['method'] ?? 'exact_name'),
                'source' => (string) ($r['source'] ?? 'text_match'),
                'showinfo_type_id' => $r['showinfo_type_id'] ?? null,
                'token_offset' => $r['token_offset'] ?? null,
                'created_at' => $now,
            ];
        }
        DB::table('eve_log_entity_resolutions')->upsert(
            $rows,
            ['eve_log_event_id', 'token', 'resolved_entity_type'],
            ['resolved_entity_id', 'resolved_entity_name', 'resolution_confidence',
             'resolution_method', 'source', 'showinfo_type_id', 'token_offset'],
        );
        return count($rows);
    }

    private function lookupSystemById(int $sid): ?string
    {
        $row = DB::table('ref_solar_systems')->where('id', $sid)->select('name')->first();
        return $row ? (string) $row->name : null;
    }

    private function lookupEntityById(int $eid): ?string
    {
        $row = DB::table('esi_entity_names')->where('entity_id', $eid)->select('name')->first();
        return $row ? (string) $row->name : null;
    }

    /**
     * @return array{0:int,1:string}|null  [system_id, name]
     */
    private function lookupSystem(string $token): ?array
    {
        if (array_key_exists($token, $this->systemCache)) {
            return $this->systemCache[$token];
        }
        $row = DB::table('ref_solar_systems')
            ->where('name', $token)
            ->select('id', 'name')
            ->first();
        $hit = $row ? [(int) $row->id, (string) $row->name] : null;
        $this->systemCache[$token] = $hit;
        return $hit;
    }

    /**
     * @return array{0:int,1:string,2:string}|null  [entity_id, name, category]
     */
    private function lookupEntity(string $name): ?array
    {
        $key = mb_strtolower($name);
        if (array_key_exists($key, $this->entityCache)) {
            return $this->entityCache[$key];
        }
        $row = DB::table('esi_entity_names')
            ->whereIn('category', ['character', 'corporation', 'alliance'])
            ->where('name', $name)
            ->select('entity_id', 'name', 'category')
            ->first();
        $hit = $row ? [(int) $row->entity_id, (string) $row->name, (string) $row->category] : null;
        $this->entityCache[$key] = $hit;
        return $hit;
    }

    private function isStopword(string $candidate): bool
    {
        $low = mb_strtolower($candidate);
        return in_array($low, self::STOPWORDS, true);
    }
}

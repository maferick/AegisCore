<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\UsersCharacters\Models\Character;
use App\Domains\UsersCharacters\Models\EveServiceToken;
use App\Services\Eve\Esi\EsiClient;
use App\Services\Eve\Esi\EsiException;
use App\Services\Eve\ServiceTokenAuthorizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scrape ansiblex jump-bridge corridors from ESI.
 *
 * Prefers the access-list-scoped path that the market structure
 * picker already uses (see StructurePickerService), so we work with
 * any authorised character that has docking rights — no Director
 * role required:
 *
 *   1. /characters/{id}/search/?categories=structure&search=<q> →
 *      ACL-filtered list of structure IDs the character can reach.
 *   2. /universe/structures/{id}/ → name + type_id + solar_system_id.
 *
 * Ansiblex Jump Gates are type_id=35841 and conventionally named
 * "<src> » <dest>" (with minor arrow variants). We parse the
 * destination system name out of the structure name + use the
 * structure's own solar_system_id as the source. Unparseable names
 * get warned + skipped rather than inserting wrong corridors.
 */
class ScrapeAnsiblexCommand extends Command
{
    protected $signature = 'map:scrape-ansiblex
                            {--query= : Search string passed to /characters/{id}/search/ (default: "»")}
                            {--dry-run : Print what would change, do not write}';

    protected $description = 'Scrape ansiblex corridors from ESI via /characters/{id}/search/ + /universe/structures/{id}/.';

    private const ANSIBLEX_TYPE_ID = 35841;

    public function handle(EsiClient $esi, ServiceTokenAuthorizer $auth): int
    {
        $tokens = EveServiceToken::query()->get();
        if ($tokens->isEmpty()) {
            $this->warn('No EveServiceToken rows found. Authorise a character first.');
            return 0;
        }

        $dry = (bool) $this->option('dry-run');
        $query = (string) ($this->option('query') ?: '»');
        $nameToId = DB::table('ref_solar_systems')->pluck('id', 'name')->all();

        $totalSeen = 0;
        $totalWritten = 0;
        $totalSkipped = 0;

        foreach ($tokens as $token) {
            // Need both the search scope (ACL-filtered structure list)
            // and the universe-read scope (structure detail). Roles not
            // required — docking rights are enough.
            if (! $token->hasScope('esi-search.search_structures.v1')
                || ! $token->hasScope('esi-universe.read_structures.v1')) {
                continue;
            }
            $char = Character::query()->where('character_id', $token->character_id)->first();
            if ($char === null) continue;
            $cid = (int) $char->character_id;
            $corpId = (int) ($char->corporation_id ?? 0);
            $allyId = $char->alliance_id !== null ? (int) $char->alliance_id : null;

            $this->info("Character {$cid} ({$char->character_name}) — searching structures matching " . json_encode($query) . '…');
            try {
                $bearer = $auth->freshAccessToken($token);
            } catch (Throwable $e) {
                $this->error("  token refresh failed: {$e->getMessage()}");
                continue;
            }

            try {
                $searchResp = $esi->get(
                    "/characters/{$cid}/search/",
                    ['categories' => 'structure', 'search' => $query],
                    $bearer,
                    forceRefresh: true,
                );
            } catch (EsiException $e) {
                $this->error("  search failed: {$e->getMessage()}");
                continue;
            }
            $structureIds = array_values(array_map(
                static fn ($id) => (int) $id,
                (array) ($searchResp->body['structure'] ?? []),
            ));
            $this->line('  ' . count($structureIds) . ' structure(s) matched.');

            foreach ($structureIds as $sid) {
                try {
                    $detail = $esi->get("/universe/structures/{$sid}/", [], $bearer, forceRefresh: true);
                } catch (EsiException $e) {
                    continue;  // lost access between search + detail, or expired
                }
                $body = $detail->body ?? [];
                $typeId = (int) ($body['type_id'] ?? 0);
                if ($typeId !== self::ANSIBLEX_TYPE_ID) continue;
                $totalSeen++;

                $srcId = (int) ($body['solar_system_id'] ?? 0);
                $name = (string) ($body['name'] ?? '');
                if (! preg_match('/[»⇌→>]\s*([A-Za-z0-9][A-Za-z0-9\-\. ]*)/u', $name, $m)) {
                    $this->warn("  unparseable: {$name}");
                    $totalSkipped++;
                    continue;
                }
                $destName = preg_split('/\s+/', trim($m[1]), 2)[0];
                $destId = $nameToId[$destName] ?? null;
                if ($destId === null || $srcId === 0) {
                    $this->warn("  could not resolve dest '{$destName}' (name: {$name})");
                    $totalSkipped++;
                    continue;
                }

                [$lo, $hi] = $srcId < (int) $destId ? [$srcId, (int) $destId] : [(int) $destId, $srcId];
                if ($dry) {
                    $this->line("  would upsert: {$name} — {$lo}↔{$hi}");
                    continue;
                }
                DB::table('ansiblex_jump_bridges')->updateOrInsert(
                    ['from_system_id' => $lo, 'to_system_id' => $hi],
                    [
                        'alliance_id' => $allyId,
                        'structure_id' => $sid,
                        'name' => $name,
                        'last_seen_at' => now(),
                        'updated_at' => now(),
                    ],
                );
                $totalWritten++;
            }
        }
        $this->info("Scan done. Seen: {$totalSeen} · Written: {$totalWritten} · Skipped: {$totalSkipped}");
        return 0;
    }
}

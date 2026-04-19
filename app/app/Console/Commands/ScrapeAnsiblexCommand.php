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
 * Scrape ansiblex jump-bridge corridors from ESI for every
 * authorised service character whose token carries
 * `esi-corporations.read_structures.v1`.
 *
 * Ansiblex Jump Gate type_id = 35841. The destination system isn't a
 * direct field on the /corporations/{id}/structures/ payload, but
 * operators conventionally name the gate "<src> » <dest>" (or with
 * arrow variants), so we parse the destination system name out of
 * the structure name. Unparseable names are logged + skipped rather
 * than poisoning the table with wrong corridors.
 */
class ScrapeAnsiblexCommand extends Command
{
    protected $signature = 'map:scrape-ansiblex
                            {--corp= : Only scrape this corporation_id}
                            {--dry-run : Print what would change, do not write}';

    protected $description = 'Scrape ansiblex corridors from ESI for every authorised corp structures token.';

    private const ANSIBLEX_TYPE_ID = 35841;

    public function handle(EsiClient $esi, ServiceTokenAuthorizer $auth): int
    {
        $tokens = EveServiceToken::query()->get();
        if ($tokens->isEmpty()) {
            $this->warn('No EveServiceToken rows found. Authorise a corp structures scope first.');
            return 0;
        }

        $onlyCorp = $this->option('corp') ? (int) $this->option('corp') : null;
        $dry = (bool) $this->option('dry-run');
        $nameToId = DB::table('ref_solar_systems')->pluck('id', 'name')->all();

        $totalSeen = 0;
        $totalWritten = 0;
        $totalSkipped = 0;

        foreach ($tokens as $token) {
            if (! $token->hasScope('esi-corporations.read_structures.v1')) {
                continue;
            }
            $char = Character::query()->where('character_id', $token->character_id)->first();
            if ($char === null || $char->corporation_id === null) {
                $this->warn("Token for character {$token->character_id} has no linked corporation — skipping.");
                continue;
            }
            $corpId = (int) $char->corporation_id;
            if ($onlyCorp !== null && $corpId !== $onlyCorp) continue;

            $this->info("Corporation {$corpId} — fetching structures…");
            try {
                $bearer = $auth->freshAccessToken($token);
            } catch (Throwable $e) {
                $this->error("  token refresh failed: {$e->getMessage()}");
                continue;
            }

            $page = 1;
            while (true) {
                try {
                    $resp = $esi->get(
                        "/v4/corporations/{$corpId}/structures/",
                        ['page' => $page],
                        $bearer,
                    );
                } catch (EsiException $e) {
                    $this->error("  page {$page} failed: {$e->getMessage()}");
                    break;
                }
                $rows = $resp->body ?? [];
                if (! is_array($rows) || $rows === []) break;

                foreach ($rows as $struct) {
                    $typeId = (int) ($struct['type_id'] ?? 0);
                    if ($typeId !== self::ANSIBLEX_TYPE_ID) continue;
                    $totalSeen++;

                    $srcId = (int) ($struct['solar_system_id'] ?? 0);
                    $structureId = (int) ($struct['structure_id'] ?? 0);
                    $name = (string) ($struct['name'] ?? '');

                    // Match the destination system name after »,
                    // tolerating alternative arrow characters.
                    if (! preg_match('/[»⇌→>]\s*([A-Za-z0-9][A-Za-z0-9\-\. ]*)/u', $name, $m)) {
                        $this->warn("  unparseable ansiblex name: {$name}");
                        $totalSkipped++;
                        continue;
                    }
                    $destName = trim($m[1]);
                    // Drop trailing qualifiers (" (staging)", numbers, etc).
                    $destName = preg_split('/\s+/', $destName, 2)[0];
                    $destId = $nameToId[$destName] ?? null;
                    if ($destId === null || $srcId === 0) {
                        $this->warn("  could not resolve '{$destName}' in ref_solar_systems (name: {$name})");
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
                            'alliance_id' => $char->alliance_id ?? null,
                            'structure_id' => $structureId,
                            'name' => $name,
                            'last_seen_at' => now(),
                            'updated_at' => now(),
                        ],
                    );
                    $totalWritten++;
                }
                if (count($rows) < 250) break;  // default ESI page size
                $page++;
            }
        }
        $this->info("Scan done. Seen: {$totalSeen} · Written: {$totalWritten} · Skipped: {$totalSkipped}");
        return 0;
    }
}

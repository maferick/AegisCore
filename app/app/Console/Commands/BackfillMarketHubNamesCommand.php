<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Markets\Models\MarketHub;
use App\Domains\Markets\Models\MarketHubCollector;
use App\Domains\Markets\Services\StructurePickerService;
use App\Services\Eve\MarketTokenAuthorizer;
use Illuminate\Console\Command;

/**
 * Resolve missing structure_name / solar_system_id / region_id for
 * player-structure market_hubs via ESI /universe/structures/{id}.
 * Uses any active collector token that carries
 * esi-universe.read_structures.v1.
 */
class BackfillMarketHubNamesCommand extends Command
{
    protected $signature = 'markets:backfill-hub-names {--hub=* : only these hub ids} {--force : re-resolve even if name already set}';

    protected $description = 'Backfill market_hubs.structure_name / solar_system_id / region_id from ESI';

    public function handle(StructurePickerService $picker, MarketTokenAuthorizer $auth): int
    {
        $q = MarketHub::query()->where('location_type', 'player_structure');
        $filter = array_map('intval', (array) $this->option('hub'));
        if ($filter) $q->whereIn('id', $filter);
        if (! $this->option('force')) $q->whereNull('structure_name');

        $hubs = $q->get();
        if ($hubs->isEmpty()) {
            $this->info('No hubs to resolve.');
            return self::SUCCESS;
        }

        foreach ($hubs as $hub) {
            $collector = MarketHubCollector::query()
                ->where('hub_id', $hub->id)
                ->where('is_active', 1)
                ->orderByDesc('is_primary')
                ->with('token')
                ->first();
            if ($collector === null || $collector->token === null) {
                $this->warn("hub {$hub->id}: no active collector, skipping");
                continue;
            }
            try {
                $access = $auth->freshAccessToken($collector->token);
            } catch (\Throwable $e) {
                $this->warn("hub {$hub->id}: token refresh failed: {$e->getMessage()}");
                continue;
            }
            $resolved = $picker->resolve($collector->character_id, $access, [(int) $hub->location_id]);
            if ($resolved === []) {
                $this->warn("hub {$hub->id}: ESI returned no resolution");
                continue;
            }
            $c = $resolved[0];
            $hub->structure_name = $c['name'];
            $hub->solar_system_id = $c['solar_system_id'];
            $hub->region_id = $c['region_id'];
            $hub->save();
            $this->info("hub {$hub->id}: {$c['name']} ({$c['system_name']})");
        }

        return self::SUCCESS;
    }
}

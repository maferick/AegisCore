<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/intelligence/leaderboards — top-10 of everything.
 *
 * Pure-read leaderboard board with a window switcher (24h / 7d /
 * 30d / 90d). Three sections:
 *
 *   - Combat — your bloc's pilots only (kills, losses, ISK,
 *     favourite hulls)
 *   - Coalition — coalition-wide activity (hot systems, top
 *     hostile alliances seen, top hostile corps)
 *   - Fleet — bloc-scoped fleet participation surface
 *
 * Read-only. No actions, no mutations. Each section uses a
 * LIMIT 10 + indexed range filter on killed_at — total cost
 * stays under the 2 s page-render envelope, so we run the
 * queries fresh each render rather than caching DB row objects
 * (Redis stdClass round-trip stripped classes to
 * __PHP_Incomplete_Class on this stack).
 */
class Leaderboards extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationLabel = 'Leaderboards';

    protected static string|UnitEnum|null $navigationGroup = 'Strategic';

    protected static ?int $navigationSort = 15;

    protected static ?string $title = 'Leaderboards';

    protected static ?string $slug = 'intelligence/leaderboards';

    protected string $view = 'filament.portal.pages.leaderboards';

    public ?string $window = '7d';

    private const WINDOWS = [
        '24h' => '1 DAY',
        '7d'  => '7 DAY',
        '30d' => '30 DAY',
        '90d' => '90 DAY',
    ];

    public function mount(): void
    {
        $w = (string) (request()->query('window') ?? '7d');
        if (! isset(self::WINDOWS[$w])) {
            $w = '7d';
        }
        $this->window = $w;
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) {
            return ['no_bloc' => true];
        }
        $window = $this->window ?? '7d';
        $interval = self::WINDOWS[$window];

        // Resolve the bloc's alliance ids — drives "your bloc's
        // pilots" filters for combat leaderboards.
        $blocAllianceIds = DB::table('coalition_entity_labels')
            ->where('entity_type', 'alliance')
            ->where('bloc_id', $blocId)
            ->where('is_active', 1)
            ->pluck('entity_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $data = [
            'top_killers'         => $this->topKillers($interval, $blocAllianceIds),
            'top_losses'          => $this->topLosses($interval, $blocAllianceIds),
            'top_isk_destroyed'   => $this->topIskDestroyed($interval, $blocAllianceIds),
            'top_isk_lost'        => $this->topIskLost($interval, $blocAllianceIds),
            'fav_hulls_killing'   => $this->favHullsKilling($interval, $blocAllianceIds),
            'fav_hulls_lost'      => $this->favHullsLost($interval, $blocAllianceIds),
            'hot_systems'         => $this->hotSystems($interval),
            'hostile_alliances'   => $this->hostileAlliances($interval, $blocAllianceIds),
            'hostile_corps'       => $this->hostileCorps($interval, $blocAllianceIds),
            'fleet_hours'         => $this->fleetHours($interval, $blocId),
            'fleet_killers'       => $this->fleetKillers($interval, $blocId),
            'fleet_talkers'       => $this->fleetTalkers($interval, $blocId),
        ];

        return [
            'no_bloc' => false,
            'window' => $window,
            'available_windows' => array_keys(self::WINDOWS),
            'bloc_alliance_count' => count($blocAllianceIds),
        ] + $data;
    }

    // --- combat (bloc pilots) ---------------------------------------

    private function topKillers(string $interval, array $blocAllianceIds): array
    {
        if (! $blocAllianceIds) return [];
        $ph = implode(',', array_fill(0, count($blocAllianceIds), '?'));
        return DB::select("
            SELECT ka.character_id, en.name AS character_name, alli.name AS alliance_name,
                   COUNT(DISTINCT ka.killmail_id) AS n
              FROM killmail_attackers ka
              JOIN killmails k ON k.killmail_id = ka.killmail_id
              LEFT JOIN esi_entity_names en ON en.entity_id = ka.character_id AND en.category = 'character'
              LEFT JOIN esi_entity_names alli ON alli.entity_id = ka.alliance_id AND alli.category = 'alliance'
             WHERE k.killed_at >= NOW() - INTERVAL {$interval}
               AND ka.alliance_id IN ({$ph})
               AND ka.character_id IS NOT NULL
             GROUP BY ka.character_id, en.name, alli.name
             ORDER BY n DESC
             LIMIT 10
        ", $blocAllianceIds);
    }

    private function topLosses(string $interval, array $blocAllianceIds): array
    {
        if (! $blocAllianceIds) return [];
        $ph = implode(',', array_fill(0, count($blocAllianceIds), '?'));
        return DB::select("
            SELECT k.victim_character_id AS character_id, en.name AS character_name,
                   alli.name AS alliance_name, COUNT(*) AS n
              FROM killmails k
              LEFT JOIN esi_entity_names en ON en.entity_id = k.victim_character_id AND en.category = 'character'
              LEFT JOIN esi_entity_names alli ON alli.entity_id = k.victim_alliance_id AND alli.category = 'alliance'
             WHERE k.killed_at >= NOW() - INTERVAL {$interval}
               AND k.victim_alliance_id IN ({$ph})
               AND k.victim_character_id IS NOT NULL
             GROUP BY k.victim_character_id, en.name, alli.name
             ORDER BY n DESC
             LIMIT 10
        ", $blocAllianceIds);
    }

    private function topIskDestroyed(string $interval, array $blocAllianceIds): array
    {
        if (! $blocAllianceIds) return [];
        $ph = implode(',', array_fill(0, count($blocAllianceIds), '?'));
        return DB::select("
            SELECT ka.character_id, en.name AS character_name, alli.name AS alliance_name,
                   SUM(k.total_value) AS isk
              FROM killmail_attackers ka
              JOIN killmails k ON k.killmail_id = ka.killmail_id
              LEFT JOIN esi_entity_names en ON en.entity_id = ka.character_id AND en.category = 'character'
              LEFT JOIN esi_entity_names alli ON alli.entity_id = ka.alliance_id AND alli.category = 'alliance'
             WHERE k.killed_at >= NOW() - INTERVAL {$interval}
               AND ka.alliance_id IN ({$ph})
               AND ka.character_id IS NOT NULL
             GROUP BY ka.character_id, en.name, alli.name
             ORDER BY isk DESC
             LIMIT 10
        ", $blocAllianceIds);
    }

    private function topIskLost(string $interval, array $blocAllianceIds): array
    {
        if (! $blocAllianceIds) return [];
        $ph = implode(',', array_fill(0, count($blocAllianceIds), '?'));
        return DB::select("
            SELECT k.victim_character_id AS character_id, en.name AS character_name,
                   alli.name AS alliance_name, SUM(k.total_value) AS isk
              FROM killmails k
              LEFT JOIN esi_entity_names en ON en.entity_id = k.victim_character_id AND en.category = 'character'
              LEFT JOIN esi_entity_names alli ON alli.entity_id = k.victim_alliance_id AND alli.category = 'alliance'
             WHERE k.killed_at >= NOW() - INTERVAL {$interval}
               AND k.victim_alliance_id IN ({$ph})
               AND k.victim_character_id IS NOT NULL
             GROUP BY k.victim_character_id, en.name, alli.name
             ORDER BY isk DESC
             LIMIT 10
        ", $blocAllianceIds);
    }

    private function favHullsKilling(string $interval, array $blocAllianceIds): array
    {
        if (! $blocAllianceIds) return [];
        $ph = implode(',', array_fill(0, count($blocAllianceIds), '?'));
        return DB::select("
            SELECT t.name AS hull, COUNT(DISTINCT ka.killmail_id) AS n
              FROM killmail_attackers ka
              JOIN killmails k ON k.killmail_id = ka.killmail_id
              LEFT JOIN ref_item_types t ON t.id = ka.ship_type_id
             WHERE k.killed_at >= NOW() - INTERVAL {$interval}
               AND ka.alliance_id IN ({$ph})
               AND ka.ship_type_id IS NOT NULL
             GROUP BY t.name
             ORDER BY n DESC
             LIMIT 10
        ", $blocAllianceIds);
    }

    private function favHullsLost(string $interval, array $blocAllianceIds): array
    {
        if (! $blocAllianceIds) return [];
        $ph = implode(',', array_fill(0, count($blocAllianceIds), '?'));
        return DB::select("
            SELECT k.victim_ship_type_name AS hull, COUNT(*) AS n
              FROM killmails k
             WHERE k.killed_at >= NOW() - INTERVAL {$interval}
               AND k.victim_alliance_id IN ({$ph})
               AND k.victim_ship_type_name IS NOT NULL
             GROUP BY k.victim_ship_type_name
             ORDER BY n DESC
             LIMIT 10
        ", $blocAllianceIds);
    }

    // --- coalition-wide / hostile -----------------------------------

    private function hotSystems(string $interval): array
    {
        return DB::select("
            SELECT s.name AS system_name, COUNT(*) AS n,
                   SUM(k.total_value) AS isk
              FROM killmails k
              LEFT JOIN ref_solar_systems s ON s.id = k.solar_system_id
             WHERE k.killed_at >= NOW() - INTERVAL {$interval}
               AND k.solar_system_id IS NOT NULL
             GROUP BY s.name
             ORDER BY n DESC
             LIMIT 10
        ");
    }

    private function hostileAlliances(string $interval, array $blocAllianceIds): array
    {
        // Top alliances seen as ATTACKERS that are NOT in our bloc.
        $exclude = $blocAllianceIds === [] ? '0' : implode(',', $blocAllianceIds);
        return DB::select("
            SELECT ka.alliance_id, en.name AS alliance_name,
                   COUNT(DISTINCT ka.killmail_id) AS n,
                   SUM(k.total_value) / COUNT(DISTINCT ka.killmail_id) AS avg_isk
              FROM killmail_attackers ka
              JOIN killmails k ON k.killmail_id = ka.killmail_id
              LEFT JOIN esi_entity_names en ON en.entity_id = ka.alliance_id AND en.category = 'alliance'
             WHERE k.killed_at >= NOW() - INTERVAL {$interval}
               AND ka.alliance_id IS NOT NULL
               AND ka.alliance_id NOT IN ({$exclude})
             GROUP BY ka.alliance_id, en.name
             ORDER BY n DESC
             LIMIT 10
        ");
    }

    private function hostileCorps(string $interval, array $blocAllianceIds): array
    {
        $exclude = $blocAllianceIds === [] ? '0' : implode(',', $blocAllianceIds);
        return DB::select("
            SELECT ka.corporation_id, en.name AS corp_name, alli.name AS alliance_name,
                   COUNT(DISTINCT ka.killmail_id) AS n
              FROM killmail_attackers ka
              JOIN killmails k ON k.killmail_id = ka.killmail_id
              LEFT JOIN esi_entity_names en ON en.entity_id = ka.corporation_id AND en.category = 'corporation'
              LEFT JOIN esi_entity_names alli ON alli.entity_id = ka.alliance_id AND alli.category = 'alliance'
             WHERE k.killed_at >= NOW() - INTERVAL {$interval}
               AND ka.corporation_id IS NOT NULL
               AND (ka.alliance_id IS NULL OR ka.alliance_id NOT IN ({$exclude}))
             GROUP BY ka.corporation_id, en.name, alli.name
             ORDER BY n DESC
             LIMIT 10
        ");
    }

    // --- fleet (bloc-scoped) ----------------------------------------

    private function fleetHours(string $interval, int $blocId): array
    {
        return DB::select("
            SELECT character_name, SUM(duration_minutes) / 60.0 AS hours,
                   COUNT(*) AS sessions, SUM(killmail_count) AS km
              FROM fleet_presence_windows
             WHERE viewer_bloc_id = ?
               AND start_at >= NOW() - INTERVAL {$interval}
               AND character_name IS NOT NULL
             GROUP BY character_name
             ORDER BY hours DESC
             LIMIT 10
        ", [$blocId]);
    }

    private function fleetKillers(string $interval, int $blocId): array
    {
        return DB::select("
            SELECT character_name, SUM(killmail_count) AS km, COUNT(*) AS sessions
              FROM fleet_presence_windows
             WHERE viewer_bloc_id = ?
               AND start_at >= NOW() - INTERVAL {$interval}
               AND character_name IS NOT NULL
               AND killmail_count > 0
             GROUP BY character_name
             ORDER BY km DESC
             LIMIT 10
        ", [$blocId]);
    }

    private function fleetTalkers(string $interval, int $blocId): array
    {
        return DB::select("
            SELECT character_name, SUM(spoken_messages) AS msgs, COUNT(*) AS sessions
              FROM fleet_presence_windows
             WHERE viewer_bloc_id = ?
               AND start_at >= NOW() - INTERVAL {$interval}
               AND character_name IS NOT NULL
               AND spoken_messages > 0
             GROUP BY character_name
             ORDER BY msgs DESC
             LIMIT 10
        ", [$blocId]);
    }

    private function resolveViewerBlocId(): ?int
    {
        $override = request()->query('bloc_id');
        if ($override !== null && ctype_digit((string) $override)) {
            return (int) $override;
        }
        $user = Auth::user();
        if ($user === null) return null;
        $char = $user->characters()->first();
        if ($char === null || ! $char->alliance_id) return null;
        $blocId = DB::table('coalition_entity_labels')
            ->where('entity_type', 'alliance')
            ->where('entity_id', $char->alliance_id)
            ->where('is_active', 1)
            ->value('bloc_id');
        return $blocId ? (int) $blocId : null;
    }
}

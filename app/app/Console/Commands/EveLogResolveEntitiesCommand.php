<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\EveLogIngest\Services\EveLogEntityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * eve-log:resolve-entities — backfill eve_log_entity_resolutions for
 * existing intel_report (and optionally chat/fleet) events that
 * predate the resolver.
 *
 * Idempotent — safe to re-run. Cached entity lookups bound the SQL
 * cost regardless of repetition across messages.
 */
class EveLogResolveEntitiesCommand extends Command
{
    protected $signature = 'eve-log:resolve-entities
        {--limit=100000 : maximum events to process per run}
        {--types=intel_report : comma-separated event_types to resolve}
        {--since-hours=8760 : how many hours back to scan}
        {--reset : purge resolutions before re-run (otherwise upsert)}';

    protected $description = 'Backfill entity resolutions for parsed log events.';

    public function handle(EveLogEntityResolver $resolver): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $types = array_values(array_filter(array_map(
            fn ($s) => trim($s),
            explode(',', (string) $this->option('types')),
        )));
        $sinceHours = max(1, (int) $this->option('since-hours'));
        $reset = (bool) $this->option('reset');

        if ($reset) {
            $deleted = DB::table('eve_log_entity_resolutions')->delete();
            $this->info("Purged {$deleted} prior resolutions.");
        }

        $rows = DB::table('eve_log_events')
            ->whereIn('event_type', $types)
            ->where('event_timestamp', '>=', now()->subHours($sinceHours))
            ->whereNotNull('parsed_json')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'parsed_json']);
        $total = $rows->count();
        $this->info("Resolving {$total} events…");
        if ($total === 0) return self::SUCCESS;

        $bar = $this->output->createProgressBar($total);
        $bar->start();
        $eventsResolved = 0;
        $totalResolutions = 0;
        foreach ($rows as $r) {
            $bar->advance();
            $payload = json_decode((string) $r->parsed_json, true);
            $message = is_array($payload) ? (string) ($payload['message'] ?? '') : '';
            if ($message === '') continue;
            $resolutions = $resolver->resolve($message, is_array($payload) ? $payload : null);
            if ($resolutions === []) continue;
            $totalResolutions += $resolver->persist((int) $r->id, $resolutions);
            $eventsResolved++;
        }
        $bar->finish();
        $this->newLine(2);
        $this->info("Events with resolutions: {$eventsResolved} of {$total}");
        $this->info("Total resolutions written: {$totalResolutions}");
        return self::SUCCESS;
    }
}

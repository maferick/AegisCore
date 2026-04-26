<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\EveLogIngest\Services\EveLogParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * eve-log:reparse-events — re-run the current parser on the raw_line
 * of existing eve_log_events rows. Updates parsed_json (so the new
 * showinfo / dscan / reported_count fields appear), refreshes
 * external_dscan_url + reported_count columns, and fills
 * event_timestamp where the row had a partial-time line and the
 * file's session_started_at is now known.
 *
 * Differs from eve-log:retry-parse-errors:
 *   - that command only walks rows in eve_log_parse_errors (failures)
 *   - this command walks every event (successes too) to absorb new
 *     parser features without re-uploading
 *
 * Idempotent. Run after deploying parser changes that should produce
 * richer parsed_json for existing rows.
 */
class EveLogReparseEventsCommand extends Command
{
    protected $signature = 'eve-log:reparse-events
        {--limit=200000 : maximum events to process per run}
        {--types=intel_report,fleet_message,local_message,chat_message : event_types to reparse}';

    protected $description = 'Re-run the parser on existing event rows; pulls in newly-extracted fields (showinfo, dscan, reported_count, partial timestamps).';

    public function handle(EveLogParser $parser): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $types = array_values(array_filter(array_map(
            fn ($s) => trim($s),
            explode(',', (string) $this->option('types')),
        )));

        $rows = DB::table('eve_log_events AS e')
            ->join('eve_log_files AS f', 'f.id', '=', 'e.eve_log_file_id')
            ->whereIn('e.event_type', $types)
            ->orderBy('e.id')
            ->limit($limit)
            ->get([
                'e.id', 'e.eve_log_file_id', 'e.raw_line', 'e.event_timestamp',
                'e.line_offset',
                'f.log_type', 'f.channel_name', 'f.session_started_at',
            ]);
        $total = $rows->count();
        $this->info("Reparsing {$total} events…");
        if ($total === 0) return self::SUCCESS;

        $updated = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();
        foreach ($rows as $r) {
            $bar->advance();
            $body = ((string) $r->raw_line) . "\n";
            $events = $parser->parseEvents(
                $body,
                (string) $r->log_type,
                $r->channel_name ? (string) $r->channel_name : null,
                (int) ($r->line_offset ?? 0),
            );
            if (empty($events)) continue;
            $e = $events[0];
            $parsed = null;
            if (! empty($e['parsed_json'])) {
                $decoded = json_decode((string) $e['parsed_json'], true);
                if (is_array($decoded)) $parsed = $decoded;
            }
            // Partial-time → assemble timestamp from session date.
            $eventTs = $e['event_timestamp'] ?? null;
            if ($eventTs === null && $parsed && ! empty($parsed['partial_time']) && $r->session_started_at) {
                $datePart = substr((string) $r->session_started_at, 0, 10);
                if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $datePart)) {
                    $eventTs = $datePart . ' ' . $parsed['partial_time'];
                }
            }
            if ($eventTs === null) {
                $eventTs = $r->event_timestamp;
            }
            DB::table('eve_log_events')
                ->where('id', $r->id)
                ->update([
                    'event_type' => $e['event_type'] ?? 'unknown',
                    'event_timestamp' => $eventTs,
                    'actor_name' => $e['actor_name'] ?? null,
                    'channel_name' => $e['channel_name'] ?? null,
                    'parsed_json' => $e['parsed_json'] ?? null,
                    'external_dscan_url' => $parsed['dscan_url'] ?? null,
                    'reported_count' => $parsed['reported_count'] ?? null,
                ]);
            $updated++;
        }
        $bar->finish();
        $this->newLine(2);
        $this->info("Updated: {$updated} of {$total}");
        return self::SUCCESS;
    }
}

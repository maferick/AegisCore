<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\EveLogIngest\Services\EveLogParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * eve-log:retry-parse-errors — replay the eve_log_parse_errors queue
 * through the current parser. When a previously-unknown line now
 * classifies cleanly, the corresponding eve_log_events row is
 * upgraded (event_type / actor_name / channel / parsed_json) and the
 * error row flips to 'reparsed_ok'. Otherwise retry_count increments
 * and status moves to 'retried' so the operator-side queue still
 * shows it as worked.
 *
 * Use after deploying parser improvements that should newly classify
 * previously-unknown patterns. Idempotent.
 */
class EveLogRetryParseErrorsCommand extends Command
{
    protected $signature = 'eve-log:retry-parse-errors
        {--limit=10000 : maximum errors to process in one run}
        {--status=open : initial status filter (open|retried|all)}
        {--dry-run : compute what would change without writing}';

    protected $description = 'Replay eve_log_parse_errors through the current parser; upgrade events that now classify cleanly.';

    public function handle(EveLogParser $parser): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $statusFilter = (string) $this->option('status');
        $dry = (bool) $this->option('dry-run');
        $now = now();

        $q = DB::table('eve_log_parse_errors AS e')
            ->join('eve_log_files AS f', 'f.id', '=', 'e.eve_log_file_id')
            ->select(
                'e.id', 'e.eve_log_file_id', 'e.raw_line', 'e.line_offset',
                'e.retry_count', 'e.status',
                'f.log_type', 'f.channel_name',
            )
            ->orderBy('e.id')
            ->limit($limit);
        if ($statusFilter !== 'all') {
            $q->where('e.status', $statusFilter);
        }
        $rows = $q->get();
        $total = $rows->count();
        $this->info("Replaying {$total} parse_error rows" . ($dry ? ' (dry-run)' : ''));

        if ($total === 0) return self::SUCCESS;

        $upgraded = 0;
        $stillBroken = 0;
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
            $hasGood = ! empty($events) && ($events[0]['event_type'] ?? 'unknown') !== 'unknown';
            if ($dry) {
                if ($hasGood) $upgraded++; else $stillBroken++;
                continue;
            }
            if ($hasGood) {
                $e = $events[0];
                DB::table('eve_log_events')
                    ->where('eve_log_file_id', $r->eve_log_file_id)
                    ->where('line_offset', $r->line_offset)
                    ->update([
                        'event_type' => $e['event_type'],
                        'event_timestamp' => $e['event_timestamp'] ?? null,
                        'actor_name' => $e['actor_name'] ?? null,
                        'channel_name' => $e['channel_name'] ?? null,
                        'parsed_json' => $e['parsed_json'] ?? null,
                    ]);
                DB::table('eve_log_parse_errors')
                    ->where('id', $r->id)
                    ->update([
                        'status' => 'reparsed_ok',
                        'retry_count' => (int) $r->retry_count + 1,
                        'last_retried_at' => $now,
                        'updated_at' => $now,
                    ]);
                $upgraded++;
            } else {
                DB::table('eve_log_parse_errors')
                    ->where('id', $r->id)
                    ->update([
                        'status' => 'retried',
                        'retry_count' => (int) $r->retry_count + 1,
                        'last_retried_at' => $now,
                        'updated_at' => $now,
                    ]);
                $stillBroken++;
            }
        }
        $bar->finish();
        $this->newLine(2);
        $this->info("Upgraded: {$upgraded} · Still broken: {$stillBroken}");
        return self::SUCCESS;
    }
}

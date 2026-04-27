<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Domains\EveLogIngest\Services\EveLogParser;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/uploader-errors — parser failure review queue.
 *
 * Shows open eve_log_parse_errors rows for files belonging to the
 * signed-in user. Operators can retry a line (re-runs the current
 * parser on raw_line; on success the row is marked reparsed_ok and
 * the corresponding eve_log_events row is updated) or dismiss.
 *
 * The page is user-scoped — only file rows whose owning client
 * belongs to Auth::user() show up. The raw-log ABAC layer (commit
 * follow-up) further restricts the raw_line column to permitted
 * roles.
 */
class EveLogUploaderErrors extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Parser Errors';

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'EVE Log Parser Errors';

    protected static ?string $slug = 'uploader-errors';

    protected string $view = 'filament.portal.pages.eve-log-uploader-errors';

    public string $statusFilter = 'open';
    public string $reasonFilter = '';
    public string $clientFilter = '';

    public function mount(): void
    {
        $this->statusFilter = (string) request()->query('status', 'open');
        $this->reasonFilter = (string) request()->query('reason', '');
        $this->clientFilter = (string) request()->query('client_id', '');
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $user = Auth::user();
        if ($user === null) return ['no_user' => true];

        $q = DB::table('eve_log_parse_errors AS e')
            ->join('eve_log_files AS f', 'f.id', '=', 'e.eve_log_file_id')
            ->where('f.user_id', $user->id);

        if ($this->statusFilter !== '') {
            $q->where('e.status', $this->statusFilter);
        }
        if ($this->reasonFilter !== '') {
            $q->where('e.reason', $this->reasonFilter);
        }
        if ($this->clientFilter !== '') {
            $q->where('f.client_id', $this->clientFilter);
        }

        $rows = $q->select(
                'e.id', 'e.eve_log_file_id', 'e.raw_line', 'e.reason', 'e.detail',
                'e.line_offset', 'e.status', 'e.retry_count', 'e.last_retried_at',
                'e.created_at',
                'f.filename', 'f.log_type', 'f.channel_name', 'f.client_id',
            )
            ->orderByDesc('e.created_at')
            ->limit(500)
            ->get();

        $reasonCounts = DB::table('eve_log_parse_errors AS e')
            ->join('eve_log_files AS f', 'f.id', '=', 'e.eve_log_file_id')
            ->where('f.user_id', $user->id)
            ->where('e.status', 'open')
            ->groupBy('e.reason')
            ->select('e.reason', DB::raw('COUNT(*) AS n'))
            ->orderByDesc('n')
            ->pluck('n', 'reason')
            ->all();

        return [
            'no_user' => false,
            'rows' => $rows,
            'reason_counts' => $reasonCounts,
            'status_filter' => $this->statusFilter,
            'reason_filter' => $this->reasonFilter,
            'client_filter' => $this->clientFilter,
        ];
    }

    public function retry(int $errorId): void
    {
        $user = Auth::user();
        if ($user === null) return;
        $row = DB::table('eve_log_parse_errors AS e')
            ->join('eve_log_files AS f', 'f.id', '=', 'e.eve_log_file_id')
            ->where('e.id', $errorId)
            ->where('f.user_id', $user->id)
            ->select('e.id', 'e.raw_line', 'e.line_offset', 'e.eve_log_file_id', 'e.retry_count',
                'f.log_type', 'f.channel_name')
            ->first();
        if ($row === null) return;

        $parser = app(EveLogParser::class);
        // parseEvents expects newline-terminated content; ensure one.
        $body = ((string) $row->raw_line) . "\n";
        $events = $parser->parseEvents($body, (string) $row->log_type, $row->channel_name, (int) ($row->line_offset ?? 0));
        $now = now();

        if (! empty($events) && ($events[0]['event_type'] ?? 'unknown') !== 'unknown') {
            // Successfully reparsed — update the events row that
            // matches this file + offset, and mark the error closed.
            $e = $events[0];
            DB::table('eve_log_events')
                ->where('eve_log_file_id', $row->eve_log_file_id)
                ->where('line_offset', $row->line_offset)
                ->update([
                    'event_type' => $e['event_type'],
                    'event_timestamp' => $e['event_timestamp'] ?? null,
                    'actor_name' => $e['actor_name'] ?? null,
                    'channel_name' => $e['channel_name'] ?? null,
                    'parsed_json' => $e['parsed_json'] ?? null,
                ]);
            DB::table('eve_log_parse_errors')
                ->where('id', $row->id)
                ->update([
                    'status' => 'reparsed_ok',
                    'retry_count' => (int) $row->retry_count + 1,
                    'last_retried_at' => $now,
                    'last_retried_by' => $user->id,
                    'updated_at' => $now,
                ]);
        } else {
            DB::table('eve_log_parse_errors')
                ->where('id', $row->id)
                ->update([
                    'status' => 'retried',
                    'retry_count' => (int) $row->retry_count + 1,
                    'last_retried_at' => $now,
                    'last_retried_by' => $user->id,
                    'updated_at' => $now,
                ]);
        }
    }

    public function dismiss(int $errorId): void
    {
        $user = Auth::user();
        if ($user === null) return;
        DB::table('eve_log_parse_errors AS e')
            ->join('eve_log_files AS f', 'f.id', '=', 'e.eve_log_file_id')
            ->where('e.id', $errorId)
            ->where('f.user_id', $user->id)
            ->update([
                'e.status' => 'dismissed',
                'e.updated_at' => now(),
                'e.last_retried_by' => $user->id,
            ]);
    }
}

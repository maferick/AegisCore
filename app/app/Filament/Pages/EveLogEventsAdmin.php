<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\EveLogIngest\Services\RawLogAccessPolicy;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /admin/eve-log/events — admin cross-user raw event explorer.
 *
 * Default deny. Only renders for User::isAdmin(). Every access is
 * audited via RawLogAccessPolicy::recordListAccess so directors can
 * be held accountable for what they viewed of someone else's logs.
 *
 * Filters: actor_name, channel_name, event_type, time window. Result
 * is capped at 200 rows to bound the leak surface per audit row.
 */
class EveLogEventsAdmin extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationLabel = 'EVE Log Events';

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'EVE Log Events · raw access (audited)';

    protected static ?string $slug = 'eve-log/events';

    protected string $view = 'filament.pages.eve-log-events-admin';

    public string $actor = '';
    public string $channel = '';
    public string $type = '';
    public string $sinceHours = '24';

    public function mount(): void
    {
        if (! $this->isAdminUser()) {
            abort(403, 'Admin only.');
        }
        $this->actor = (string) request()->query('actor', '');
        $this->channel = (string) request()->query('channel', '');
        $this->type = (string) request()->query('type', '');
        $this->sinceHours = (string) request()->query('since_hours', '24');
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        if (! $this->isAdminUser()) {
            return ['no_admin' => true];
        }

        $sinceHours = (int) ($this->sinceHours !== '' ? $this->sinceHours : 24);
        if ($sinceHours <= 0 || $sinceHours > 24 * 30) $sinceHours = 24;

        $q = DB::table('eve_log_events AS e')
            ->join('eve_log_files AS f', 'f.id', '=', 'e.eve_log_file_id')
            ->where('e.created_at', '>=', now()->subHours($sinceHours));

        if ($this->actor !== '') {
            $q->where('e.actor_name', 'like', '%' . trim($this->actor) . '%');
        }
        if ($this->channel !== '') {
            $q->where('e.channel_name', 'like', '%' . trim($this->channel) . '%');
        }
        if ($this->type !== '') {
            $q->where('e.event_type', $this->type);
        }

        $rows = $q->select(
                'e.id', 'e.event_type', 'e.event_timestamp', 'e.actor_name',
                'e.channel_name', 'e.raw_line', 'e.parsed_json', 'e.line_offset',
                'f.id AS file_id', 'f.user_id', 'f.filename', 'f.client_id', 'f.log_type',
            )
            ->orderByDesc('e.event_timestamp')
            ->limit(200)
            ->get();

        // Audit the access. row_count is the number of raw_lines the
        // viewer was actually exposed to.
        $user = Auth::user();
        if ($user !== null && $rows->count() > 0) {
            app(RawLogAccessPolicy::class)->recordListAccess(
                $user,
                'eve_log_events_admin_search',
                $rows->count(),
                [
                    'actor' => $this->actor,
                    'channel' => $this->channel,
                    'type' => $this->type,
                    'since_hours' => $sinceHours,
                ],
            );
        }

        return [
            'no_admin' => false,
            'rows' => $rows,
            'actor' => $this->actor,
            'channel' => $this->channel,
            'type' => $this->type,
            'since_hours' => $sinceHours,
        ];
    }

    public function refreshSearch(): void
    {
        // No-op: Livewire re-renders pulling fresh getViewData() which
        // writes a fresh audit row. Bound to a button so each search
        // is one auditable action.
    }

    private function isAdminUser(): bool
    {
        $u = Auth::user();
        return $u !== null && method_exists($u, 'isAdmin') && $u->isAdmin();
    }
}

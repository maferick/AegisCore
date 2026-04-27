<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * /portal/uploaders — per-user EVE log uploader management.
 *
 * Lists every eve_log_upload_clients row owned by the current user,
 * with rolling stats (files, bytes, parse errors, last_offset, last
 * seen). Provides issue / rotate / revoke actions.
 *
 * The raw API token is shown EXACTLY ONCE on issue/rotate via a
 * Livewire flash message. Server stores only a sha256 hash.
 */
class EveLogUploaders extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cloud-arrow-up';

    protected static ?string $navigationLabel = 'EVE Log Uploaders';

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 8;

    protected static ?string $title = 'EVE Log Uploaders';

    protected static ?string $slug = 'uploaders';

    protected string $view = 'filament.portal.pages.eve-log-uploaders';

    public string $newDisplayName = '';
    public ?string $issuedRawToken = null;
    public ?string $issuedClientId = null;

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $user = Auth::user();
        if ($user === null) return ['no_user' => true];

        $clients = DB::table('eve_log_upload_clients AS c')
            ->where('c.user_id', $user->id)
            ->leftJoin('eve_log_files AS f', 'f.client_id', '=', 'c.client_id')
            ->select(
                'c.id', 'c.client_id', 'c.display_name', 'c.created_at',
                'c.last_seen_at', 'c.last_remote_ip', 'c.revoked_at',
                DB::raw('COUNT(DISTINCT f.id) AS files_seen'),
                DB::raw('COALESCE(SUM(f.size_received), 0) AS bytes_received'),
                DB::raw('MAX(f.last_offset) AS latest_offset'),
                DB::raw('MAX(f.last_seen_at) AS last_file_at'),
            )
            ->groupBy(
                'c.id', 'c.client_id', 'c.display_name', 'c.created_at',
                'c.last_seen_at', 'c.last_remote_ip', 'c.revoked_at',
            )
            ->orderByDesc('c.last_seen_at')
            ->get();

        // Parse error counts per client (separate query — joining on
        // file_id × client_id × open status would explode the row count).
        $errorCountsByClient = [];
        if ($clients->isNotEmpty()) {
            $clientIds = $clients->pluck('client_id')->all();
            $errorRows = DB::table('eve_log_parse_errors AS e')
                ->join('eve_log_files AS f', 'f.id', '=', 'e.eve_log_file_id')
                ->whereIn('f.client_id', $clientIds)
                ->where('e.status', 'open')
                ->groupBy('f.client_id')
                ->select('f.client_id', DB::raw('COUNT(*) AS n'))
                ->get();
            foreach ($errorRows as $r) {
                $errorCountsByClient[(string) $r->client_id] = (int) $r->n;
            }
        }

        return [
            'no_user' => false,
            'user_id' => $user->id,
            'clients' => $clients,
            'open_parse_errors_by_client' => $errorCountsByClient,
            'issued_raw_token' => $this->issuedRawToken,
            'issued_client_id' => $this->issuedClientId,
            'app_url' => config('app.url'),
        ];
    }

    public function issueToken(): void
    {
        $user = Auth::user();
        if ($user === null) return;
        $clientId = (string) Str::uuid();
        $rawToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $rawToken);
        $now = now();
        DB::table('eve_log_upload_clients')->insert([
            'user_id' => $user->id,
            'client_id' => $clientId,
            'display_name' => mb_substr($this->newDisplayName ?: 'My PC', 0, 120),
            'api_token_hash' => $hash,
            'last_seen_at' => null,
            'last_remote_ip' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'revoked_at' => null,
        ]);
        $this->issuedRawToken = $rawToken;
        $this->issuedClientId = $clientId;
        $this->newDisplayName = '';
    }

    public function rotateToken(int $clientRowId): void
    {
        $user = Auth::user();
        if ($user === null) return;
        $row = DB::table('eve_log_upload_clients')
            ->where('id', $clientRowId)
            ->where('user_id', $user->id)
            ->first();
        if ($row === null) return;
        $rawToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $rawToken);
        DB::table('eve_log_upload_clients')
            ->where('id', $row->id)
            ->update([
                'api_token_hash' => $hash,
                'revoked_at' => null,
                'updated_at' => now(),
            ]);
        $this->issuedRawToken = $rawToken;
        $this->issuedClientId = (string) $row->client_id;
    }

    public function revoke(int $clientRowId): void
    {
        $user = Auth::user();
        if ($user === null) return;
        DB::table('eve_log_upload_clients')
            ->where('id', $clientRowId)
            ->where('user_id', $user->id)
            ->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function dismissIssuedToken(): void
    {
        $this->issuedRawToken = null;
        $this->issuedClientId = null;
    }
}

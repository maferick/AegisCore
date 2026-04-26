<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues a fresh API token for the EVE log uploader.
 *
 * Tokens are stored as sha256 hashes server-side. The raw token is
 * displayed exactly once — operators must copy it into the uploader's
 * config.json. Re-running for the same client_id replaces the
 * previous token.
 */
class EveLogIngestIssueTokenCommand extends Command
{
    protected $signature = 'eve-log-ingest:issue-token
        {--user= : user id to bind the token to}
        {--client-id= : stable per-installation identifier (uuid string)}
        {--display-name= : optional human-readable label}';

    protected $description = 'Issue an API token for the EVE log uploader and print it once.';

    public function handle(): int
    {
        $userId = (int) $this->option('user');
        if ($userId <= 0) {
            $this->error('Pass --user=<id>.');
            return self::FAILURE;
        }
        $userExists = DB::table('users')->where('id', $userId)->exists();
        if (! $userExists) {
            $this->error("User {$userId} not found.");
            return self::FAILURE;
        }
        $clientId = (string) ($this->option('client-id') ?? Str::uuid());
        $displayName = $this->option('display-name');

        $rawToken = bin2hex(random_bytes(32)); // 64 hex chars
        $hash = hash('sha256', $rawToken);
        $now = now();

        DB::table('eve_log_upload_clients')->updateOrInsert(
            ['client_id' => $clientId],
            [
                'user_id' => $userId,
                'display_name' => $displayName,
                'api_token_hash' => $hash,
                'last_seen_at' => null,
                'last_remote_ip' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'revoked_at' => null,
            ],
        );

        $this->info('Token issued. Copy this once — it cannot be retrieved later:');
        $this->line('');
        $this->line("client_id: {$clientId}");
        $this->line("api_token: {$rawToken}");
        $this->line('');
        $this->info('Configure the Windows uploader\'s config.json:');
        $this->line(json_encode([
            'api_base_url' => config('app.url'),
            'api_token' => $rawToken,
            'client_id' => $clientId,
            'auto_discover_eve_logs' => true,
            'upload_interval_seconds' => 10,
            'max_chunk_bytes' => 262144,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}

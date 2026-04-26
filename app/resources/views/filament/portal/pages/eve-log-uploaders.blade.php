<x-filament-panels::page>
    @php
        $fmtBytes = function (int $b): string {
            if ($b >= 1<<30) return number_format($b / (1<<30), 2) . ' GiB';
            if ($b >= 1<<20) return number_format($b / (1<<20), 2) . ' MiB';
            if ($b >= 1<<10) return number_format($b / (1<<10), 2) . ' KiB';
            return $b . ' B';
        };
    @endphp

    <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
        <h2 style="margin:0; font-size:1.05rem; color:#e5e5e7;">EVE Log Uploaders</h2>
        <p style="font-size:0.78rem; color:#9ca3af; margin-top:0.4rem; margin-bottom:0;">
            Manage Windows uploader installations linked to your account. Each install gets a stable client_id and a rotatable API token. Tokens are stored only as sha256 hashes server-side — when issued or rotated, the raw value is shown <strong>once</strong>.
        </p>
    </div>

    @if ($issued_raw_token)
        <div class="fi-section rounded-xl shadow-sm ring-1 ring-gray-950/5 mb-4" style="background:rgba(34,197,94,0.06); border:1px solid rgba(34,197,94,0.30); padding:1rem;">
            <div style="display:flex; gap:0.6rem; align-items:flex-start; justify-content:space-between;">
                <div style="flex:1; min-width:0;">
                    <h3 style="margin:0 0 0.4rem; font-size:0.78rem; color:#86efac; text-transform:uppercase; letter-spacing:0.08em;">
                        Token issued · copy now, will not be shown again
                    </h3>
                    <div style="font-size:0.7rem; color:#7a7a82; margin-bottom:0.5rem;">
                        Paste these into <code>%APPDATA%\AegisCore\EveLogUploader\config.json</code>:
                    </div>
                    <pre style="background:rgba(0,0,0,0.40); border:1px solid rgba(255,255,255,0.05); border-radius:6px; padding:0.7rem 0.9rem; font-family:ui-monospace,monospace; font-size:0.72rem; color:#e5e5e7; white-space:pre-wrap; word-break:break-all; margin:0;">{{ json_encode([
    'api_base_url' => $app_url,
    'api_token' => $issued_raw_token,
    'client_id' => $issued_client_id,
    'auto_discover_eve_logs' => true,
    'upload_interval_seconds' => 10,
    'max_chunk_bytes' => 262144,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
                <button wire:click="dismissIssuedToken"
                        style="font-size:0.6rem; padding:4px 10px; background:rgba(255,255,255,0.05); color:#cbd5e1; border:1px solid rgba(255,255,255,0.10); border-radius:4px; cursor:pointer; text-transform:uppercase; letter-spacing:0.06em;">
                    dismiss
                </button>
            </div>
        </div>
    @endif

    {{-- Issue new client --}}
    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
        <h3 style="margin:0 0 0.6rem; font-size:0.72rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">
            Issue a new client
        </h3>
        <div style="display:flex; gap:0.5rem; align-items:center;">
            <input type="text" wire:model="newDisplayName" placeholder="Display name (e.g. Home PC, Work Laptop)"
                   style="flex:1; background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.10); color:#e5e5e7; padding:0.45rem 0.6rem; border-radius:5px; font-size:0.78rem;">
            <button wire:click="issueToken"
                    style="font-size:0.7rem; padding:0.45rem 0.9rem; background:rgba(99,102,241,0.15); color:#c7d2fe; border:1px solid rgba(99,102,241,0.40); border-radius:5px; cursor:pointer; text-transform:uppercase; letter-spacing:0.06em;">
                Issue token
            </button>
        </div>
    </div>

    {{-- Existing clients --}}
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="overflow:hidden;">
        @if (count($clients) === 0)
            <div style="padding:1.25rem; font-size:0.8rem; color:#9ca3af;">
                No uploader clients linked yet. Issue one above to start shipping logs from a Windows machine.
            </div>
        @else
            <table style="width:100%; border-collapse:collapse; font-size:0.78rem;">
                <thead>
                    <tr style="background:rgba(255,255,255,0.03); color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em; font-size:0.55rem;">
                        <th style="text-align:left; padding:0.55rem 0.7rem;">Client</th>
                        <th style="text-align:left; padding:0.55rem 0.7rem;">Last seen</th>
                        <th style="text-align:right; padding:0.55rem 0.7rem;">Files</th>
                        <th style="text-align:right; padding:0.55rem 0.7rem;">Bytes received</th>
                        <th style="text-align:right; padding:0.55rem 0.7rem;">Latest offset</th>
                        <th style="text-align:right; padding:0.55rem 0.7rem;">Open errors</th>
                        <th style="text-align:left; padding:0.55rem 0.7rem;">State</th>
                        <th style="text-align:right; padding:0.55rem 0.7rem;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($clients as $c)
                        @php
                            $errN = $open_parse_errors_by_client[(string) $c->client_id] ?? 0;
                            $isRevoked = $c->revoked_at !== null;
                            $stateColor = $isRevoked ? '#9ca3af'
                                : ($c->last_seen_at && \Carbon\Carbon::parse($c->last_seen_at)->gt(now()->subHour())
                                    ? '#86efac' : '#fde68a');
                            $stateLabel = $isRevoked ? 'revoked'
                                : ($c->last_seen_at ? 'live' : 'never_seen');
                        @endphp
                        <tr style="border-top:1px solid rgba(255,255,255,0.04);">
                            <td style="padding:0.5rem 0.7rem;">
                                <div style="color:#e5e5e7; font-weight:500;">{{ $c->display_name ?? '(unnamed)' }}</div>
                                <div style="font-size:0.6rem; color:#6b7280; font-family:ui-monospace,monospace;">{{ $c->client_id }}</div>
                            </td>
                            <td style="padding:0.5rem 0.7rem; color:#cbd5e1; font-size:0.7rem;">
                                {{ $c->last_seen_at ? \Carbon\Carbon::parse($c->last_seen_at)->diffForHumans() : '—' }}
                                @if ($c->last_remote_ip)
                                    <div style="font-size:0.55rem; color:#7a7a82;">{{ $c->last_remote_ip }}</div>
                                @endif
                            </td>
                            <td style="padding:0.5rem 0.7rem; text-align:right; color:#cbd5e1;">
                                {{ number_format((int) $c->files_seen) }}
                            </td>
                            <td style="padding:0.5rem 0.7rem; text-align:right; color:#cbd5e1;">
                                {{ $fmtBytes((int) $c->bytes_received) }}
                            </td>
                            <td style="padding:0.5rem 0.7rem; text-align:right; color:#cbd5e1; font-family:ui-monospace,monospace; font-size:0.7rem;">
                                {{ $c->latest_offset !== null ? number_format((int) $c->latest_offset) : '—' }}
                            </td>
                            <td style="padding:0.5rem 0.7rem; text-align:right; color:{{ $errN > 0 ? '#fca5a5' : '#7a7a82' }};">
                                @if ($errN > 0)
                                    <a href="/portal/uploader-errors?client_id={{ $c->client_id }}" style="color:#fca5a5; text-decoration:none;">{{ number_format($errN) }} →</a>
                                @else
                                    0
                                @endif
                            </td>
                            <td style="padding:0.5rem 0.7rem;">
                                <span style="font-size:0.55rem; color:{{ $stateColor }}; text-transform:uppercase; letter-spacing:0.06em;">{{ $stateLabel }}</span>
                            </td>
                            <td style="padding:0.5rem 0.7rem; text-align:right;">
                                @if (! $isRevoked)
                                    <button wire:click="rotateToken({{ $c->id }})"
                                            wire:confirm="Rotate this token? The current token will stop working immediately."
                                            style="font-size:0.55rem; padding:3px 8px; background:rgba(234,179,8,0.10); color:#fde68a; border:1px solid rgba(234,179,8,0.30); border-radius:4px; cursor:pointer; text-transform:uppercase; letter-spacing:0.06em; margin-right:0.25rem;">
                                        rotate
                                    </button>
                                    <button wire:click="revoke({{ $c->id }})"
                                            wire:confirm="Revoke this token? The uploader will stop being able to upload until you issue a new one."
                                            style="font-size:0.55rem; padding:3px 8px; background:rgba(239,68,68,0.08); color:#fca5a5; border:1px solid rgba(239,68,68,0.30); border-radius:4px; cursor:pointer; text-transform:uppercase; letter-spacing:0.06em;">
                                        revoke
                                    </button>
                                @else
                                    <button wire:click="rotateToken({{ $c->id }})"
                                            style="font-size:0.55rem; padding:3px 8px; background:rgba(99,102,241,0.10); color:#c7d2fe; border:1px solid rgba(99,102,241,0.30); border-radius:4px; cursor:pointer; text-transform:uppercase; letter-spacing:0.06em;">
                                        re-issue
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-filament-panels::page>

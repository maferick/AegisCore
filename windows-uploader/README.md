# AegisCore EVE Log Uploader

Windows .NET 8 Worker Service that ships EVE Online client logs to
the AegisCore Laravel ingest endpoint.

## What it does

- Watches one or more EVE log folders (auto-discovered + manually
  configured).
- Uploads `.txt` files in append-safe chunks. Tracks per-file byte
  offsets so a restart resumes where it left off.
- Computes a SHA256 over each chunk and verifies server-side; resyncs
  cleanly on `409 offset_mismatch`.
- Never modifies, deletes, or scans outside the configured folders.
- Never blocks EVE (opens files with FileShare.ReadWrite|Delete).

## What it doesn't do

- No screenshots, no arbitrary file upload, no filesystem scan
  outside EVE log folders.
- No CI scoring on the client. The server parses + analyses.
- No automatic punitive action — the upstream Counter-Intel surface
  is advisory.

## Install (manual, dev workflow)

1. Build:

   ```
   dotnet publish AegisCore.EveLogUploader -c Release -r win-x64
   ```

2. Issue an API token on the server:

   ```
   docker compose exec php-fpm php artisan eve-log-ingest:issue-token \
       --user=<your_user_id> [--client-id=<uuid>] [--display-name="My PC"]
   ```

   The artisan command prints both `client_id` and `api_token` exactly
   once. The token is stored only as a sha256 hash server-side.

3. Edit `%APPDATA%\AegisCore\EveLogUploader\config.json`. The first
   run creates a stub. Fill in `api_base_url`, `api_token`, and
   `client_id`:

   ```json
   {
     "api_base_url": "https://winterco.killsineve.online",
     "api_token": "<paste_here>",
     "client_id": "<paste_here>",
     "watch_paths": [],
     "auto_discover_eve_logs": true,
     "upload_interval_seconds": 10,
     "max_chunk_bytes": 262144
   }
   ```

4. Run as console (dev):

   ```
   AegisCore.EveLogUploader.exe
   ```

   …or install as a service:

   ```
   sc.exe create "AegisCore EVE Log Uploader" \
       binPath="C:\path\to\AegisCore.EveLogUploader.exe" start=auto
   sc.exe start "AegisCore EVE Log Uploader"
   ```

## State

- Config: `%APPDATA%\AegisCore\EveLogUploader\config.json`
- State: `%APPDATA%\AegisCore\EveLogUploader\state.json` (per-file
  uploaded offsets, last sha256, last status/error)

State is rewritten atomically (`*.tmp` rename). Safe to delete to
re-upload from offset 0 — the server will still reject duplicates
via offset continuity check.

## Auto-discovered paths

The discovery probes the following relative to `%USERPROFILE%`:

- `Documents\EVE\logs\Gamelogs`
- `Documents\EVE\logs\Chatlogs`
- `OneDrive\Documents\EVE\logs\Gamelogs`
- `OneDrive\Documents\EVE\logs\Chatlogs`
- `OneDrive\Downloads\Documenten\EVE\logs\Gamelogs`
- `OneDrive\Downloads\Documenten\EVE\logs\Chatlogs`
- `Downloads\Documenten\EVE\logs\Gamelogs`
- `Downloads\Documenten\EVE\logs\Chatlogs`

Only existing folders are added. The user can add more in
`watch_paths`. The uploader never scans outside this set.

## Reliability

- Exponential backoff per-file on consecutive retryable failures
  (max 60s).
- 4xx responses (other than 409 / 429) are non-retryable and surface
  in `state.json` as `last_error` so the user can see why.
- Network / timeout errors are retryable.
- Reload of `config.json` happens every 60 seconds without restart.

## Server contract

Single endpoint:

```
POST /api/eve-log-ingest/chunk
Authorization: Bearer <api_token>

{
  "client_id": "...",
  "source_path_hash": "...",
  "filename": "20260425_180000_2_5_1234567.txt",
  "log_type": "unknown",
  "folder_hint": "...\\Chatlogs",
  "offset_start": 12345,
  "offset_end": 23456,
  "chunk_sha256": "...",
  "content": "...",
  "local_modified_at": "2026-04-25T18:34:53Z"
}

200 OK     {"status":"ok",              "accepted_offset": 23456}
409 conflict {"status":"offset_mismatch","accepted_offset": 18000}
4xx        {"status":"error", "message":"..."}
```

`source_path_hash` is `sha256(lowercase(absolute_path))` so the same
log file is recognised across uploader runs even if the OS reports
slightly different casings.

## Privacy

- Raw chat / fleet / intel logs may contain sensitive operational
  intelligence. Server-side access is permission-gated; never share
  the API token.
- Token rotation: re-running `eve-log-ingest:issue-token` for the
  same `client_id` rotates the token — old token stops working.

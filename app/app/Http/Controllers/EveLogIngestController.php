<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\EveLogIngest\Services\EveLogParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3 — POST /api/eve-log-ingest/chunk
 *
 * Append-safe chunked log uploader. Validates Bearer token, payload
 * shape, sha256 integrity, and offset continuity. On success persists
 * the chunk receipt, parses complete lines into events, and returns
 * the new accepted_offset.
 *
 * On offset mismatch (client thinks file is at byte X but server has
 * already accepted up to Y), responds 409 with the server's
 * accepted_offset so the uploader can resume cleanly.
 */
class EveLogIngestController extends Controller
{
    public function chunk(Request $request, EveLogParser $parser): JsonResponse
    {
        // 1. Bearer auth.
        $auth = $request->header('Authorization');
        if (! $auth || ! preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return response()->json(['status' => 'error', 'message' => 'missing bearer token'], 401);
        }
        $rawToken = trim($m[1]);
        $tokenHash = hash('sha256', $rawToken);
        $client = DB::table('eve_log_upload_clients')
            ->where('api_token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->first();
        if ($client === null) {
            return response()->json(['status' => 'error', 'message' => 'invalid token'], 401);
        }

        // 2. Payload validation.
        $data = $request->validate([
            'client_id' => 'required|string|max:64',
            'source_path_hash' => 'required|string|size:64',
            'filename' => 'required|string|max:255',
            'log_type' => 'nullable|string|in:gamelog,chatlog,fleet,local,intel,unknown',
            'listener' => 'nullable|string|max:120',
            'channel_name' => 'nullable|string|max:120',
            'channel_id' => 'nullable|string|max:64',
            'session_started_at' => 'nullable|date',
            'offset_start' => 'required|integer|min:0',
            'offset_end' => 'required|integer|min:0',
            'chunk_sha256' => 'required|string|size:64',
            'content' => 'required|string',
            'local_modified_at' => 'nullable|date',
            'folder_hint' => 'nullable|string|max:255',
        ]);

        if ($data['client_id'] !== (string) $client->client_id) {
            return response()->json(['status' => 'error', 'message' => 'token/client_id mismatch'], 401);
        }
        if ($data['offset_end'] < $data['offset_start']) {
            return response()->json(['status' => 'error', 'message' => 'offset_end < offset_start'], 422);
        }

        // 3. Hash check on the raw content. Uploader sends UTF-8 bytes.
        $contentBytes = $data['content'];
        $expectedLen = $data['offset_end'] - $data['offset_start'];
        if (strlen($contentBytes) !== $expectedLen) {
            return response()->json([
                'status' => 'error',
                'message' => 'content length does not match offset window',
                'expected' => $expectedLen,
                'got' => strlen($contentBytes),
            ], 422);
        }
        $sha = hash('sha256', $contentBytes);
        if (strcasecmp($sha, $data['chunk_sha256']) !== 0) {
            return response()->json(['status' => 'error', 'message' => 'sha256 mismatch'], 422);
        }

        // 4. Resolve / create the file record.
        $now = now();
        $logType = $data['log_type'] ?? null;
        if ($logType === null || $logType === 'unknown') {
            $logType = EveLogParser::detectLogType(
                $data['folder_hint'] ?? null,
                $data['channel_name'] ?? null,
                $data['listener'] ?? null,
            );
        }

        $file = DB::table('eve_log_files')
            ->where('client_id', $data['client_id'])
            ->where('source_path_hash', $data['source_path_hash'])
            ->first();
        if ($file === null) {
            // First chunk for this file: must start at offset 0.
            if ((int) $data['offset_start'] !== 0) {
                return response()->json([
                    'status' => 'offset_mismatch',
                    'accepted_offset' => 0,
                    'message' => 'no record for file yet; first chunk must start at offset 0',
                ], 409);
            }
            $newId = DB::table('eve_log_files')->insertGetId([
                'user_id' => $client->user_id,
                'client_id' => $data['client_id'],
                'source_path_hash' => $data['source_path_hash'],
                'filename' => $data['filename'],
                'log_type' => $logType,
                'listener' => $data['listener'] ?? null,
                'channel_name' => $data['channel_name'] ?? null,
                'channel_id' => $data['channel_id'] ?? null,
                'session_started_at' => $data['session_started_at'] ?? null,
                'size_received' => 0,
                'last_offset' => 0,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $file = DB::table('eve_log_files')->where('id', $newId)->first();
        }

        // 5. Offset continuity. Append-only: chunk must start exactly
        //    at last_offset.
        if ((int) $data['offset_start'] !== (int) $file->last_offset) {
            return response()->json([
                'status' => 'offset_mismatch',
                'accepted_offset' => (int) $file->last_offset,
                'message' => 'chunk start does not align with server-tracked last_offset',
            ], 409);
        }

        // 6. Begin transaction. Insert chunk receipt, update file
        //    metadata, parse + persist events.
        $accepted = (int) $data['offset_end'];
        try {
            DB::transaction(function () use ($parser, $data, $file, $logType, $now, $contentBytes, $accepted, $client): void {
                // Update header fields if uploader supplied richer metadata
                // and the file row is still missing them.
                $headerUpdates = [];
                foreach (['listener', 'channel_name', 'channel_id', 'session_started_at'] as $k) {
                    if (! empty($data[$k]) && empty($file->$k)) {
                        $headerUpdates[$k] = $data[$k];
                    }
                }
                if (($file->log_type ?? 'unknown') === 'unknown' && $logType !== 'unknown') {
                    $headerUpdates['log_type'] = $logType;
                }

                DB::table('eve_log_chunks')->insert([
                    'eve_log_file_id' => $file->id,
                    'offset_start' => (int) $data['offset_start'],
                    'offset_end' => $accepted,
                    'byte_length' => strlen($contentBytes),
                    'chunk_sha256' => mb_strtolower($data['chunk_sha256']),
                    'received_at' => $now,
                ]);

                // Header detection on the very first chunk.
                if ((int) $data['offset_start'] === 0) {
                    $hdr = $parser->parseHeader($contentBytes);
                    foreach (['listener', 'channel_name', 'channel_id', 'session_started_at'] as $k) {
                        if (! empty($hdr[$k]) && empty($file->$k) && empty($headerUpdates[$k] ?? null)) {
                            $headerUpdates[$k] = $hdr[$k];
                        }
                    }
                }

                $effectiveChannel = $headerUpdates['channel_name'] ?? $file->channel_name ?? ($data['channel_name'] ?? null);

                $events = $parser->parseEvents(
                    $contentBytes,
                    $headerUpdates['log_type'] ?? ($file->log_type ?? $logType),
                    $effectiveChannel,
                    (int) $data['offset_start'],
                );

                if ($events !== []) {
                    $rows = [];
                    foreach ($events as $e) {
                        $rows[] = [
                            'eve_log_file_id' => $file->id,
                            'event_timestamp' => $e['event_timestamp'] ?? null,
                            'event_type' => $e['event_type'] ?? 'unknown',
                            'actor_name' => $e['actor_name'] ?? null,
                            'system_name' => $e['system_name'] ?? null,
                            'channel_name' => $e['channel_name'] ?? null,
                            'raw_line' => mb_substr((string) ($e['raw_line'] ?? ''), 0, 65535),
                            'parsed_json' => $e['parsed_json'] ?? null,
                            'line_offset' => $e['line_offset'] ?? null,
                            'created_at' => $now,
                        ];
                    }
                    foreach (array_chunk($rows, 500) as $batch) {
                        DB::table('eve_log_events')->insert($batch);
                    }
                }

                DB::table('eve_log_files')
                    ->where('id', $file->id)
                    ->update(array_merge($headerUpdates, [
                        'size_received' => DB::raw('size_received + ' . strlen($contentBytes)),
                        'last_offset' => $accepted,
                        'last_seen_at' => $now,
                        'updated_at' => $now,
                    ]));

                DB::table('eve_log_upload_clients')
                    ->where('id', $client->id)
                    ->update([
                        'last_seen_at' => $now,
                        'last_remote_ip' => mb_substr((string) request()->ip(), 0, 64),
                        'updated_at' => $now,
                    ]);
            });
        } catch (\Throwable $e) {
            Log::error('eve_log_ingest chunk failed', [
                'client_id' => $data['client_id'] ?? null,
                'filename' => $data['filename'] ?? null,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['status' => 'error', 'message' => 'server error'], 500);
        }

        return response()->json(['status' => 'ok', 'accepted_offset' => $accepted]);
    }
}

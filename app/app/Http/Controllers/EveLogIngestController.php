<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\EveLogIngest\Services\EveLogEntityResolver;
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
    public function chunk(Request $request, EveLogParser $parser, EveLogEntityResolver $resolver): JsonResponse
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

        // 2. Payload validation. Accept either content_b64 (preferred,
        // raw bytes base64-encoded — wire-safe for UTF-16 / control
        // chars / BOM) or content (legacy plain string).
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
            'content' => 'nullable|string',
            'content_b64' => 'nullable|string',
            'local_modified_at' => 'nullable|date',
            'folder_hint' => 'nullable|string|max:255',
        ]);
        if (empty($data['content']) && empty($data['content_b64'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'either content or content_b64 is required',
            ], 422);
        }

        if ($data['client_id'] !== (string) $client->client_id) {
            return response()->json(['status' => 'error', 'message' => 'token/client_id mismatch'], 401);
        }
        if ($data['offset_end'] < $data['offset_start']) {
            return response()->json(['status' => 'error', 'message' => 'offset_end < offset_start'], 422);
        }

        // Auth ACK — stamp the client row as soon as the bearer is
        // validated, before payload validation runs. Lets the
        // /portal/uploaders status page show "live" even when chunks
        // are being rejected for content reasons (so the operator can
        // distinguish "uploader is reaching us" from "uploader is
        // silent").
        DB::table('eve_log_upload_clients')
            ->where('id', $client->id)
            ->update([
                'last_seen_at' => now(),
                'last_remote_ip' => mb_substr((string) $request->ip(), 0, 64),
                'updated_at' => now(),
            ]);

        // 3. Decode + hash check. Prefer content_b64 — base64 is the
        // only wire-safe path for raw log bytes (handles UTF-16 BOM,
        // control chars, surrogate halves without JSON normalisation
        // mutating them). Plain `content` stays for legacy/manual test
        // clients.
        if (! empty($data['content_b64'])) {
            $decoded = base64_decode((string) $data['content_b64'], true);
            if ($decoded === false) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'content_b64 is not valid base64',
                ], 422);
            }
            $contentBytes = $decoded;
        } else {
            $contentBytes = (string) $data['content'];
        }
        $sha = hash('sha256', $contentBytes);
        if (strcasecmp($sha, $data['chunk_sha256']) !== 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'sha256 mismatch',
                'expected_sha256' => mb_strtolower($data['chunk_sha256']),
                'got_sha256' => $sha,
                'got_length' => strlen($contentBytes),
                'transport' => empty($data['content_b64']) ? 'plain' : 'base64',
            ], 422);
        }
        $effectiveOffsetEnd = (int) $data['offset_start'] + strlen($contentBytes);

        // Encoding normalisation. EVE chat logs on Windows can be
        // UTF-16 LE (older builds) or UTF-8 (modern builds), with or
        // without BOM. Detect by leading BOM bytes and convert to
        // UTF-8 before parsing. The on-the-wire bytes (and the sha256
        // we just verified) stay UTF-16 — only the parser-side string
        // is normalised.
        $parserText = $this->normaliseToUtf8($contentBytes);

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
        $accepted = $effectiveOffsetEnd;
        try {
            DB::transaction(function () use ($parser, $resolver, $data, $file, $logType, $now, $contentBytes, $parserText, $accepted, $client): void {
                // Update header fields if uploader supplied richer metadata
                // and the file row is still missing them.
                $headerUpdates = [];
                foreach (['listener', 'channel_name', 'channel_id', 'session_started_at'] as $k) {
                    if (! empty($data[$k]) && empty($file->$k)) {
                        $headerUpdates[$k] = $data[$k];
                    }
                }
                // Re-detect log_type when we learn channel_name via the
                // header parse on chunk 0. Also upgrades older 'unknown'
                // / 'chatlog' rows once a fleet/local/intel channel name
                // appears.
                $effectiveChannelForDetect = $headerUpdates['channel_name']
                    ?? $file->channel_name
                    ?? ($data['channel_name'] ?? null);
                $detected = EveLogParser::detectLogType(
                    $data['folder_hint'] ?? null,
                    $effectiveChannelForDetect,
                    $data['listener'] ?? null,
                );
                if ($detected !== 'unknown' && $detected !== ($file->log_type ?? null)) {
                    $headerUpdates['log_type'] = $detected;
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
                    $hdr = $parser->parseHeader($parserText);
                    foreach (['listener', 'channel_name', 'channel_id', 'session_started_at'] as $k) {
                        if (! empty($hdr[$k]) && empty($file->$k) && empty($headerUpdates[$k] ?? null)) {
                            $headerUpdates[$k] = $hdr[$k];
                        }
                    }
                }

                $effectiveChannel = $headerUpdates['channel_name'] ?? $file->channel_name ?? ($data['channel_name'] ?? null);

                $events = $parser->parseEvents(
                    $parserText,
                    $headerUpdates['log_type'] ?? ($file->log_type ?? $logType),
                    $effectiveChannel,
                    (int) $data['offset_start'],
                );

                if ($events !== []) {
                    $rows = [];
                    $errorRows = [];
                    foreach ($events as $e) {
                        $rawLine = mb_substr((string) ($e['raw_line'] ?? ''), 0, 65535);
                        $rows[] = [
                            'eve_log_file_id' => $file->id,
                            'event_timestamp' => $e['event_timestamp'] ?? null,
                            'event_type' => $e['event_type'] ?? 'unknown',
                            'actor_name' => $e['actor_name'] ?? null,
                            'system_name' => $e['system_name'] ?? null,
                            'channel_name' => $e['channel_name'] ?? null,
                            'raw_line' => $rawLine,
                            'parsed_json' => $e['parsed_json'] ?? null,
                            'line_offset' => $e['line_offset'] ?? null,
                            'created_at' => $now,
                        ];
                        // Enqueue every 'unknown' line into the parser
                        // failure queue so operators can review what
                        // the parser couldn't classify. Pure-empty
                        // lines are filtered upstream so anything
                        // landing here is a real parse miss.
                        if (($e['event_type'] ?? null) === 'unknown') {
                            $reason = 'unknown_event';
                            $detail = null;
                            $parsed = $e['parsed_json'] ?? null;
                            if (is_string($parsed)) {
                                $decoded = json_decode($parsed, true);
                                if (is_array($decoded) && isset($decoded['reason'])) {
                                    $reason = mb_substr((string) $decoded['reason'], 0, 80);
                                    $detail = $parsed;
                                }
                            }
                            $errorRows[] = [
                                'eve_log_file_id' => $file->id,
                                'eve_log_event_id' => null,
                                'raw_line' => $rawLine,
                                'line_offset' => $e['line_offset'] ?? null,
                                'reason' => $reason,
                                'detail' => $detail,
                                'status' => 'open',
                                'retry_count' => 0,
                                'last_retried_at' => null,
                                'last_retried_by' => null,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }
                    foreach (array_chunk($rows, 500) as $batch) {
                        DB::table('eve_log_events')->insert($batch);
                    }
                    if ($errorRows !== []) {
                        foreach (array_chunk($errorRows, 500) as $batch) {
                            DB::table('eve_log_parse_errors')->insert($batch);
                        }
                    }
                    // Resolve entities (system codes + character /
                    // corp / alliance names) for chat-class events.
                    // Chunk-by-chunk since the unique key (event_id,
                    // token, type) needs the freshly-inserted ids.
                    $resolveTypes = ['intel_report', 'fleet_message', 'local_message', 'chat_message'];
                    $resolvable = array_filter($events, fn ($e) => in_array($e['event_type'] ?? '', $resolveTypes, true));
                    if ($resolvable) {
                        $offsets = array_values(array_filter(array_map(fn ($e) => $e['line_offset'] ?? null, $resolvable)));
                        $idMap = [];
                        if ($offsets) {
                            $idRows = DB::table('eve_log_events')
                                ->where('eve_log_file_id', $file->id)
                                ->whereIn('line_offset', $offsets)
                                ->select('id', 'line_offset')
                                ->get();
                            foreach ($idRows as $idRow) {
                                $idMap[(int) $idRow->line_offset] = (int) $idRow->id;
                            }
                        }
                        foreach ($resolvable as $e) {
                            $offset = $e['line_offset'] ?? null;
                            $eventId = $offset !== null ? ($idMap[(int) $offset] ?? null) : null;
                            if ($eventId === null) continue;
                            $payload = $e['parsed_json'] ?? null;
                            $msg = '';
                            if (is_string($payload)) {
                                $decoded = json_decode($payload, true);
                                if (is_array($decoded)) $msg = (string) ($decoded['message'] ?? '');
                            }
                            if ($msg === '') continue;
                            $resolutions = $resolver->resolve($msg);
                            if ($resolutions !== []) {
                                $resolver->persist($eventId, $resolutions);
                            }
                        }
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

                // last_seen_at already stamped on auth ACK above.
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

    /**
     * Detect the chunk's text encoding from leading BOM bytes and
     * return a UTF-8 string suitable for the parser. Falls back to
     * the raw bytes interpreted as UTF-8 when no BOM is present.
     *
     * Detection covers:
     *   FF FE        — UTF-16 LE BOM
     *   FE FF        — UTF-16 BE BOM
     *   EF BB BF     — UTF-8 BOM (passed through; parser strips on its own)
     *   none         — assume UTF-8 (modern EVE)
     */
    private function normaliseToUtf8(string $bytes): string
    {
        $len = strlen($bytes);
        if ($len === 0) return '';
        if ($len >= 2 && $bytes[0] === "\xFF" && $bytes[1] === "\xFE") {
            $converted = @mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
            return $converted !== false ? $converted : $bytes;
        }
        if ($len >= 2 && $bytes[0] === "\xFE" && $bytes[1] === "\xFF") {
            $converted = @mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
            return $converted !== false ? $converted : $bytes;
        }
        return $bytes;
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\EveLogIngest\Services;

/**
 * Phase 3 — EVE log parser v1.
 *
 * Stateless line-by-line classifier. Given a chunk of log text plus
 * optional file context (log_type, channel_name), returns a list of
 * structured events ready to insert into eve_log_events.
 *
 * Recognised line shapes (anchored on the leading "[ DATE TIME ]"
 * timestamp):
 *
 *   [ 2026.04.25 18:34:53 ] CharacterName > message               → chat
 *   [ 2026.04.25 18:34:26 ] EVE System > message                  → session_event
 *   [ 2026.04.25 18:39:31 ] (combat) message                      → combat_event
 *   [ 2026.04.25 18:38:57 ] (notify) message                      → notify_event
 *   [ 2026.04.25 18:34:53 ] CharNameA > nv {Char Name B}          → intel_report (if channel is intel)
 *
 * Header lines are detected separately (pre-payload section above
 * the first timestamped line):
 *
 *   Listener: Pilot Name
 *   Session Started: 2026.04.25 18:00:00
 *   Channel ID:      2_5_1234567
 *   Channel Name:    NameOfChannel
 *
 * Header values are not events themselves — the controller persists
 * them onto eve_log_files for the file-level metadata.
 */
final class EveLogParser
{
    /**
     * Channel name fragments that imply intel-channel handling. Case
     * insensitive substring match. Operators can extend by adding
     * environment-specific names later — the spec is intentionally
     * loose about which channel is "intel".
     */
    private const INTEL_CHANNEL_HINTS = ['intel', 'spy', 'red light', 'cs intel', 'wartime'];

    private const TS_REGEX = '/^\[\s*(\d{4}\.\d{2}\.\d{2}\s+\d{2}:\d{2}:\d{2})\s*\]\s*(.*)$/u';

    /**
     * Header parser.
     *
     * @return array{listener:?string, channel_id:?string, channel_name:?string, session_started_at:?string}
     */
    public function parseHeader(string $text): array
    {
        $out = ['listener' => null, 'channel_id' => null, 'channel_name' => null, 'session_started_at' => null];
        // EVE writes UTF-16 with BOM; we expect the controller to have
        // decoded already. Accept stray BOM defensively.
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $lines = preg_split('/\r\n|\r|\n/u', (string) $text);
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') continue;
            // Stop scanning after we hit the first timestamped line.
            if (preg_match(self::TS_REGEX, $line)) break;
            if (preg_match('/^Listener:\s*(.+)$/iu', $line, $m)) {
                $out['listener'] = trim($m[1]);
            } elseif (preg_match('/^Session\s+Started:\s*(\d{4}\.\d{2}\.\d{2}\s+\d{2}:\d{2}:\d{2})/iu', $line, $m)) {
                $out['session_started_at'] = self::eveDateTimeToIso($m[1]);
            } elseif (preg_match('/^Channel\s+ID:\s*(.+)$/iu', $line, $m)) {
                $out['channel_id'] = trim($m[1]);
            } elseif (preg_match('/^Channel\s+Name:\s*(.+)$/iu', $line, $m)) {
                $out['channel_name'] = trim($m[1]);
            }
        }
        return $out;
    }

    /**
     * Parse a chunk of body text into structured events. Lines that
     * cannot be parsed produce 'unknown' events with raw_line populated
     * so nothing is silently dropped.
     *
     * @param  string  $body                 chunk content (UTF-8 decoded)
     * @param  string  $logType              gamelog | chatlog | fleet | local | intel | unknown
     * @param  ?string $channelName          file-level channel name from header
     * @param  ?int    $startingLineOffset   absolute offset (bytes) of the first byte of body
     * @return list<array<string, mixed>>
     */
    public function parseEvents(string $body, string $logType, ?string $channelName, ?int $startingLineOffset = null): array
    {
        $events = [];
        $offset = $startingLineOffset ?? 0;
        // Iterate while preserving offsets so each event can carry its
        // own line_offset inside the source file.
        $remaining = $body;
        while ($remaining !== '') {
            $eolPos = strpos($remaining, "\n");
            if ($eolPos === false) {
                $line = $remaining;
                $consumed = strlen($remaining);
                $remaining = '';
            } else {
                $line = substr($remaining, 0, $eolPos);
                // Strip trailing \r if present (Windows EOL).
                if ($line !== '' && $line[strlen($line) - 1] === "\r") {
                    $line = substr($line, 0, -1);
                }
                $consumed = $eolPos + 1;
                $remaining = substr($remaining, $consumed);
            }
            $trim = trim((string) $line);
            if ($trim === '') {
                $offset += $consumed;
                continue;
            }
            $event = $this->classifyLine($trim, $logType, $channelName);
            if ($event !== null) {
                $event['line_offset'] = $offset;
                $event['raw_line'] = $line;
                $events[] = $event;
            }
            $offset += $consumed;
        }
        return $events;
    }

    /**
     * Classify a single trimmed line. Returns null when the line is a
     * pure header echo (already handled by parseHeader).
     *
     * @return array<string, mixed>|null
     */
    private function classifyLine(string $line, string $logType, ?string $channelName): ?array
    {
        // Header-ish lines that may appear inside the body for some EVE
        // builds. Skip them — header parsing already captured these.
        if (preg_match('/^(Listener|Session\s+Started|Channel\s+(ID|Name)):/iu', $line)) {
            return null;
        }

        // Gamelog / chatlog file decoration: a row of dashes, a single
        // word like "Gamelog" / "Chatlog", or anything that is purely
        // ascii-art separator. Skip silently — not a parse failure.
        if (preg_match('/^[-=*_\s]+$/u', $line)) return null;
        if (preg_match('/^(Gamelog|Chatlog)\s*$/iu', $line)) return null;

        if (! preg_match(self::TS_REGEX, $line, $m)) {
            return [
                'event_type' => 'unknown',
                'event_timestamp' => null,
                'actor_name' => null,
                'system_name' => null,
                'channel_name' => $channelName,
                'parsed_json' => json_encode(['reason' => 'no_timestamp_prefix']),
            ];
        }
        $timestamp = self::eveDateTimeToIso($m[1]);
        $rest = trim($m[2]);

        // (combat) / (notify) / (info|warning|question|hint|None) gamelog flavours.
        // Combat is its own bucket; everything else is a notify-class
        // UI event so we put them all under notify_event with the
        // specific gamelog_kind preserved in parsed_json.
        if (preg_match('/^\((combat|notify|info|warning|question|hint|None)\)\s+(.*)$/iu', $rest, $cm)) {
            $kind = strtolower($cm[1]);
            $msg = $cm[2];
            return [
                'event_type' => $kind === 'combat' ? 'combat_event' : 'notify_event',
                'event_timestamp' => $timestamp,
                'actor_name' => null,
                'system_name' => null,
                'channel_name' => $channelName,
                'parsed_json' => json_encode([
                    'gamelog_kind' => $kind,
                    'message' => $msg,
                ]),
            ];
        }

        // EVE System messages = session-event (joined / left local channel,
        // etc.). Detect speaker == "EVE System" before generic chat.
        if (preg_match('/^EVE\s+System\s*>\s*(.+)$/iu', $rest, $sm)) {
            return [
                'event_type' => 'session_event',
                'event_timestamp' => $timestamp,
                'actor_name' => 'EVE System',
                'system_name' => null,
                'channel_name' => $channelName,
                'parsed_json' => json_encode(['message' => trim($sm[1])]),
            ];
        }

        // Generic chat: "Speaker > message".
        if (preg_match('/^([^>]{1,160}?)\s*>\s*(.*)$/u', $rest, $cm2)) {
            $speaker = trim($cm2[1]);
            $msg = trim($cm2[2]);
            $type = self::classifyChatType($logType, $channelName, $msg);
            return [
                'event_type' => $type,
                'event_timestamp' => $timestamp,
                'actor_name' => $speaker,
                'system_name' => null,
                'channel_name' => $channelName,
                'parsed_json' => json_encode(['message' => $msg]),
            ];
        }

        return [
            'event_type' => 'unknown',
            'event_timestamp' => $timestamp,
            'actor_name' => null,
            'system_name' => null,
            'channel_name' => $channelName,
            'parsed_json' => json_encode(['reason' => 'unparsed_after_timestamp', 'rest' => $rest]),
        ];
    }

    /**
     * Channel/log-type aware chat-message subtype.
     */
    private static function classifyChatType(string $logType, ?string $channelName, string $message): string
    {
        $name = $channelName !== null ? mb_strtolower($channelName) : '';
        if ($logType === 'fleet' || str_contains($name, 'fleet')) {
            return 'fleet_message';
        }
        if ($logType === 'local' || $name === 'local') {
            return 'local_message';
        }
        if ($logType === 'intel') {
            return 'intel_report';
        }
        foreach (self::INTEL_CHANNEL_HINTS as $hint) {
            if ($name !== '' && str_contains($name, $hint)) {
                return 'intel_report';
            }
        }
        return 'chat_message';
    }

    /**
     * Convert "2026.04.25 18:34:53" → "2026-04-25 18:34:53". Returns
     * null on parse failure.
     */
    private static function eveDateTimeToIso(string $eveDt): ?string
    {
        if (! preg_match('/^(\d{4})\.(\d{2})\.(\d{2})\s+(\d{2}):(\d{2}):(\d{2})$/', trim($eveDt), $m)) {
            return null;
        }
        return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
    }

    /**
     * Detect log_type from folder + channel name + content hint. Used
     * by the controller when the uploader didn't set an explicit
     * log_type.
     */
    public static function detectLogType(?string $folderHint, ?string $channelName, ?string $listener): string
    {
        $folder = mb_strtolower((string) $folderHint);
        $channel = mb_strtolower((string) $channelName);
        if (str_contains($folder, 'gamelog')) return 'gamelog';
        if (str_contains($folder, 'chatlog') && $channel !== '') {
            if ($channel === 'local') return 'local';
            if (str_contains($channel, 'fleet')) return 'fleet';
            foreach (self::INTEL_CHANNEL_HINTS as $hint) {
                if (str_contains($channel, $hint)) return 'intel';
            }
            return 'chatlog';
        }
        if ($channel === 'local') return 'local';
        if (str_contains($channel, 'fleet')) return 'fleet';
        foreach (self::INTEL_CHANNEL_HINTS as $hint) {
            if (str_contains($channel, $hint)) return 'intel';
        }
        return 'unknown';
    }
}

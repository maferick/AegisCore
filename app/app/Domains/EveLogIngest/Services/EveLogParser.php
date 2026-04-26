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
     * insensitive substring match. Bloc-specific intel channels are
     * appended via the EVE_INTEL_CHANNEL_HINTS env var
     * (comma-separated, lowercased). Default list covers generic
     * names plus known bloc channels.
     *
     * Calibration backlog: move to a per-bloc config table so
     * leadership can edit without redeploy.
     */
    private const INTEL_CHANNEL_HINTS = [
        'intel', 'spy', 'red light', 'cs intel', 'wartime',
        'wc.vale', 'wc.tr', 'wc.ge', 'wc.vale+tr+ge',
    ];

    /**
     * @return list<string>
     */
    private static function intelHints(): array
    {
        $env = (string) (getenv('EVE_INTEL_CHANNEL_HINTS') ?: '');
        $extra = array_filter(array_map(
            fn ($s) => mb_strtolower(trim($s)),
            explode(',', $env),
        ), fn ($s) => $s !== '');
        return array_values(array_unique(array_merge(self::INTEL_CHANNEL_HINTS, $extra)));
    }

    private const TS_REGEX = '/^\[\s*(\d{4}\.\d{2}\.\d{2}\s+\d{2}:\d{2}:\d{2})\s*\]\s*(.*)$/u';
    // Some chat clients write a short [HH:MM:SS] form. We accept it
    // and store the time component; the controller infers the date
    // from the file's session_started_at when persisting.
    private const TS_REGEX_PARTIAL = '/^\[\s*(\d{2}:\d{2}:\d{2})\s*\]\s*(.*)$/u';

    /**
     * EVE rich-text showinfo link extraction. Matches:
     *   <url=showinfo:TYPE//ID>visible label</url>
     *
     * TYPE is the EVE typeID:
     *   5                          solar system → ref_solar_systems
     *   2                          corporation
     *   16159                      alliance
     *   1373-1386 + a few siblings character (race / bloodline variants)
     *   else                       fall back to esi_entity_names lookup
     */
    private const SHOWINFO_REGEX = '/<url=showinfo:(\d+)\/\/(\d+)>(.*?)<\/url>/iu';

    /** dscan.info URL — the snapshot id is everything after /v/. */
    private const DSCAN_REGEX = '/https?:\/\/dscan\.info\/(?:v\/|view\/)?([a-z0-9]+)/iu';

    /** "+N" marker in intel reports. Conservative — bounded 1-3 digits. */
    private const PLUSN_REGEX = '/(?:^|\s)\+(\d{1,3})(?=\s|$)/u';

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
        // Strip leading per-line BOM bytes. EVE chat logs (especially
        // after UTF-16→UTF-8 normalisation) place a U+FEFF marker at
        // the start of every chat line, not just file start. The
        // TS_REGEX would otherwise fail and the line would land in
        // the parser failure queue as "no_timestamp_prefix".
        $line = preg_replace('/^(?:\xEF\xBB\xBF)+/', '', $line);

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

        $timestamp = null;
        $rest = null;
        if (preg_match(self::TS_REGEX, $line, $m)) {
            $timestamp = self::eveDateTimeToIso($m[1]);
            $rest = trim($m[2]);
        } elseif (preg_match(self::TS_REGEX_PARTIAL, $line, $m)) {
            // Partial form [HH:MM:SS] — controller fills the date from
            // file session_started_at. We stash the time component
            // here and let the controller compose the final timestamp
            // before INSERT. Until then, leave it null.
            $rest = trim($m[2]);
        } else {
            return [
                'event_type' => 'unknown',
                'event_timestamp' => null,
                'actor_name' => null,
                'system_name' => null,
                'channel_name' => $channelName,
                'parsed_json' => json_encode(['reason' => 'no_timestamp_prefix']),
            ];
        }
        // Carry the partial-time hint into parsed_json so the controller
        // can assemble the full timestamp.
        $partialTime = isset($timestamp) ? null : ($m[1] ?? null);

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

        // EVE System messages — channel join / leave / MOTD broadcasts.
        // MOTD lines fire on every chat log open and shouldn't surface
        // in the dossier timeline as activity, so we split them out
        // into their own event_type.
        if (preg_match('/^EVE\s+System\s*>\s*(.+)$/iu', $rest, $sm)) {
            $msg = trim($sm[1]);
            $isMotd = (bool) preg_match('/^Channel\s+MOTD\s*:/iu', $msg);
            return [
                'event_type' => $isMotd ? 'channel_motd' : 'session_event',
                'event_timestamp' => $timestamp,
                'actor_name' => 'EVE System',
                'system_name' => null,
                'channel_name' => $channelName,
                'parsed_json' => json_encode(['message' => $msg]),
            ];
        }

        // Generic chat: "Speaker > message".
        if (preg_match('/^([^>]{1,160}?)\s*>\s*(.*)$/u', $rest, $cm2)) {
            $speaker = trim($cm2[1]);
            $msg = trim($cm2[2]);
            $type = self::classifyChatType($logType, $channelName, $msg);

            // Phase 4.4 — extract authoritative EVE rich-text content.
            $showinfoLinks = self::extractShowinfoLinks($msg);
            $dscanUrl = self::extractDscanUrl($msg);
            $reportedCount = self::extractPlusN($msg);

            $parsed = [
                'message' => $msg,
                'partial_time' => $partialTime,
            ];
            if ($showinfoLinks) {
                $parsed['showinfo'] = $showinfoLinks;
            }
            if ($dscanUrl !== null) {
                $parsed['dscan_url'] = $dscanUrl[0];
                $parsed['dscan_id'] = $dscanUrl[1];
            }
            if ($reportedCount !== null) {
                $parsed['reported_count'] = $reportedCount;
            }
            return [
                'event_type' => $type,
                'event_timestamp' => $timestamp,
                'actor_name' => $speaker,
                'system_name' => null,
                'channel_name' => $channelName,
                'parsed_json' => json_encode($parsed),
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
        foreach (self::intelHints() as $hint) {
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
    /**
     * Extract every <url=showinfo:TYPE//ID>label</url> pair from the
     * message body. Returns an ordered list of dicts.
     *
     * @return list<array{type_id:int,entity_id:int,label:string}>
     */
    public static function extractShowinfoLinks(string $msg): array
    {
        if (! preg_match_all(self::SHOWINFO_REGEX, $msg, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        foreach ($matches as $m) {
            $out[] = [
                'type_id' => (int) $m[1],
                'entity_id' => (int) $m[2],
                'label' => trim((string) $m[3]),
            ];
        }
        return $out;
    }

    /**
     * Extract the first dscan.info URL + snapshot id from a message.
     *
     * @return array{0:string,1:string}|null  [url, snapshot_id]
     */
    public static function extractDscanUrl(string $msg): ?array
    {
        if (! preg_match(self::DSCAN_REGEX, $msg, $m)) return null;
        return [$m[0], $m[1]];
    }

    public static function extractPlusN(string $msg): ?int
    {
        if (! preg_match(self::PLUSN_REGEX, $msg, $m)) return null;
        return (int) $m[1];
    }

    /**
     * Map an EVE showinfo type_id to the canonical entity-resolutions
     * type bucket. Falls back to 'unknown' for type_ids we don't yet
     * know a mapping for — the caller can still record them via
     * showinfo_type_id.
     */
    public static function showinfoTypeToEntityType(int $typeId): string
    {
        if ($typeId === 5) return 'system';            // solarSystem
        if ($typeId === 2) return 'corporation';
        if ($typeId === 16159) return 'alliance';
        // Character race+bloodline ids span 1373..1386 plus 1377/1378
        // and a handful of alts; treat the full range conservatively.
        if ($typeId >= 1373 && $typeId <= 1390) return 'character';
        // Region / constellation typeIDs.
        if ($typeId === 3) return 'region';
        return 'unknown';
    }

    public static function detectLogType(?string $folderHint, ?string $channelName, ?string $listener): string
    {
        $folder = mb_strtolower((string) $folderHint);
        $channel = mb_strtolower((string) $channelName);
        // Channel-specific overrides win when present.
        if ($channel === 'local') return 'local';
        if ($channel !== '' && str_contains($channel, 'fleet')) return 'fleet';
        foreach (self::intelHints() as $hint) {
            if ($channel !== '' && str_contains($channel, $hint)) return 'intel';
        }
        // Folder hints establish a baseline so first-chunk classification
        // works before the parser has seen the channel header.
        if (str_contains($folder, 'gamelog')) return 'gamelog';
        if (str_contains($folder, 'chatlog')) return 'chatlog';
        return 'unknown';
    }
}

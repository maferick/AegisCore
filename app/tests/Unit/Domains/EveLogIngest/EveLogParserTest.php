<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\EveLogIngest;

use App\Domains\EveLogIngest\Services\EveLogParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 3 — basic parser tests covering header, chat, system, combat,
 * notify, and unknown lines + log-type detection.
 */
class EveLogParserTest extends TestCase
{
    #[Test]
    public function parses_chatlog_header(): void
    {
        $parser = new EveLogParser();
        $body = "Channel ID:      2_5_1234567\n"
            . "Channel Name:    Pizza Fleet\n"
            . "Listener:        Test Pilot\n"
            . "Session Started: 2026.04.25 18:00:00\n"
            . "------------------------------------------------------------\n"
            . "[ 2026.04.25 18:34:53 ] Test Pilot > anchor on me\n";

        $hdr = $parser->parseHeader($body);
        $this->assertSame('Test Pilot', $hdr['listener']);
        $this->assertSame('2_5_1234567', $hdr['channel_id']);
        $this->assertSame('Pizza Fleet', $hdr['channel_name']);
        $this->assertSame('2026-04-25 18:00:00', $hdr['session_started_at']);
    }

    #[Test]
    public function classifies_chat_messages_by_channel(): void
    {
        $parser = new EveLogParser();
        $body = "[ 2026.04.25 18:34:53 ] Test Pilot > anchor on me\n";

        $events = $parser->parseEvents($body, 'fleet', 'Pizza Fleet');
        $this->assertCount(1, $events);
        $this->assertSame('fleet_message', $events[0]['event_type']);
        $this->assertSame('Test Pilot', $events[0]['actor_name']);
        $this->assertSame('2026-04-25 18:34:53', $events[0]['event_timestamp']);

        $events = $parser->parseEvents($body, 'local', 'Local');
        $this->assertSame('local_message', $events[0]['event_type']);

        $events = $parser->parseEvents($body, 'intel', 'CS Intel');
        $this->assertSame('intel_report', $events[0]['event_type']);

        $events = $parser->parseEvents($body, 'chatlog', 'Random');
        $this->assertSame('chat_message', $events[0]['event_type']);
    }

    #[Test]
    public function parses_eve_system_session_event(): void
    {
        $parser = new EveLogParser();
        $body = "[ 2026.04.25 18:34:26 ] EVE System > Channel changed to Local : Jita\n";
        $events = $parser->parseEvents($body, 'local', 'Local');

        $this->assertCount(1, $events);
        $this->assertSame('session_event', $events[0]['event_type']);
        $this->assertSame('EVE System', $events[0]['actor_name']);
    }

    #[Test]
    public function parses_combat_and_notify_gamelog(): void
    {
        $parser = new EveLogParser();
        $body = "[ 2026.04.25 18:39:31 ] (combat) <color=0xff00ffff>1234</color> from <b>Bad Guy</b>\n"
            . "[ 2026.04.25 18:38:57 ] (notify) Your ship's autopilot has been engaged.\n";
        $events = $parser->parseEvents($body, 'gamelog', null);

        $this->assertCount(2, $events);
        $this->assertSame('combat_event', $events[0]['event_type']);
        $this->assertSame('notify_event', $events[1]['event_type']);
    }

    #[Test]
    public function unknown_line_is_kept_with_reason(): void
    {
        $parser = new EveLogParser();
        $body = "this line has no timestamp prefix\n";
        $events = $parser->parseEvents($body, 'unknown', null);

        $this->assertCount(1, $events);
        $this->assertSame('unknown', $events[0]['event_type']);
        $this->assertSame('this line has no timestamp prefix', $events[0]['raw_line']);
    }

    #[Test]
    public function gamelog_continuation_lines_are_notify_not_unknown(): void
    {
        $parser = new EveLogParser();
        $body = "[ 2026.04.25 18:38:57 ] (notify) Are you sure you want to undock?\n"
            . "<br><br>This action cannot be undone.<br>\n"
            . "Do you wish to proceed?\n";
        $events = $parser->parseEvents($body, 'gamelog', null);

        $this->assertCount(3, $events);
        $this->assertSame('notify_event', $events[0]['event_type']);
        $this->assertSame('notify_event', $events[1]['event_type']);
        $this->assertSame('notify_event', $events[2]['event_type']);
        $payload1 = json_decode($events[1]['parsed_json'], true);
        $payload2 = json_decode($events[2]['parsed_json'], true);
        $this->assertSame('continuation', $payload1['gamelog_kind']);
        $this->assertSame('continuation', $payload2['gamelog_kind']);
        $this->assertSame('<br><br>This action cannot be undone.<br>', $payload1['message']);
    }

    #[Test]
    public function chatlog_continuation_still_unknown(): void
    {
        $parser = new EveLogParser();
        $body = "stray line in a chat log\n";
        $events = $parser->parseEvents($body, 'chatlog', 'Random');

        $this->assertCount(1, $events);
        $this->assertSame('unknown', $events[0]['event_type']);
    }

    #[Test]
    public function preserves_line_offset_across_chunk(): void
    {
        $parser = new EveLogParser();
        $body = "[ 2026.04.25 18:34:53 ] A > one\n[ 2026.04.25 18:34:54 ] B > two\n";
        $events = $parser->parseEvents($body, 'chatlog', 'X', startingLineOffset: 1000);

        $this->assertCount(2, $events);
        $this->assertSame(1000, $events[0]['line_offset']);
        $this->assertSame(strlen("[ 2026.04.25 18:34:53 ] A > one\n") + 1000, $events[1]['line_offset']);
    }

    #[Test]
    public function detect_log_type_from_folder_and_channel(): void
    {
        $this->assertSame('gamelog', EveLogParser::detectLogType('Documents\\EVE\\logs\\Gamelogs', null, null));
        $this->assertSame('local', EveLogParser::detectLogType('Documents\\EVE\\logs\\Chatlogs', 'Local', null));
        $this->assertSame('fleet', EveLogParser::detectLogType('Documents\\EVE\\logs\\Chatlogs', 'My Fleet', null));
        $this->assertSame('intel', EveLogParser::detectLogType('Documents\\EVE\\logs\\Chatlogs', 'CS Intel', null));
        $this->assertSame('chatlog', EveLogParser::detectLogType('Documents\\EVE\\logs\\Chatlogs', 'Random', null));
        $this->assertSame('unknown', EveLogParser::detectLogType(null, null, null));
    }
}

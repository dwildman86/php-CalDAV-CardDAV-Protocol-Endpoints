<?php

declare(strict_types=1);

namespace GhostParse\Dav\Tests;

use GhostParse\Dav\VObject\DateUtil;
use GhostParse\Dav\VObject\ObjectValidator;
use GhostParse\Dav\VObject\ParseException;
use GhostParse\Dav\VObject\Parser;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    public function testParsesFoldedLinesAndParameters(): void
    {
        $data = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:1\r\nSUMMARY:A long\r\n  summary\r\n"
            . "ATTENDEE;CN=\"Doe, Jane\";ROLE=REQ-PARTICIPANT:mailto:jane@example.com\r\n"
            . "DESCRIPTION:Line one\\nLine\\, two\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $root = Parser::parse($data);
        $event = $root->getComponents('VEVENT')[0];

        $this->assertSame('A long summary', $event->getProperty('SUMMARY')?->value);
        $attendee = $event->getProperty('ATTENDEE');
        $this->assertSame('Doe, Jane', $attendee?->getFirstParameter('CN'));
        $this->assertSame('mailto:jane@example.com', $attendee?->value);
        $this->assertSame("Line one\nLine, two", $event->getProperty('DESCRIPTION')?->getTextValue());
    }

    public function testRoundTripSerialisation(): void
    {
        $data = "BEGIN:VCARD\r\nVERSION:3.0\r\nitem1.EMAIL;TYPE=INTERNET:a@example.com\r\nEND:VCARD\r\n";
        $card = Parser::parse($data);
        $this->assertSame('item1', $card->getProperty('EMAIL')?->group);
        $this->assertSame($data, $card->serialize());
    }

    public function testFoldsLongLines(): void
    {
        $data = "BEGIN:VCARD\r\nNOTE:" . str_repeat('é', 100) . "\r\nEND:VCARD\r\n";
        $serialised = Parser::parse($data)->serialize();
        foreach (explode("\r\n", $serialised) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
        $this->assertSame(str_repeat('é', 100), Parser::parse($serialised)->getProperty('NOTE')?->value);
    }

    public function testRejectsMismatchedEnd(): void
    {
        $this->expectException(ParseException::class);
        Parser::parse("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nEND:VCALENDAR\r\n");
    }

    public function testMetadataForRecurringEvent(): void
    {
        $data = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:r\r\nDTSTART;TZID=Europe/Berlin:20250101T100000\r\n"
            . "DURATION:PT1H\r\nRRULE:FREQ=DAILY;UNTIL=20250110T000000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $meta = ObjectValidator::calendarObject($data);

        $this->assertSame('r', $meta->uid);
        $this->assertSame('VEVENT', $meta->componentType);
        $this->assertSame(strtotime('2025-01-01 09:00:00 UTC') - 86400, $meta->firstOccurrence);
        $this->assertGreaterThan(strtotime('2025-01-10 00:00:00 UTC'), $meta->lastOccurrence);

        $infinite = str_replace(';UNTIL=20250110T000000Z', '', $data);
        $this->assertNull(ObjectValidator::calendarObject($infinite)->lastOccurrence);
    }

    public function testDateParsing(): void
    {
        $this->assertSame([strtotime('2025-03-01 00:00:00 UTC'), true], DateUtil::parseValue('20250301'));
        $this->assertSame([strtotime('2025-03-01 12:30:00 UTC'), false], DateUtil::parseValue('20250301T123000Z'));
        $this->assertSame(strtotime('2025-07-01 10:00:00 UTC') - 7200, DateUtil::parseValue('20250701T100000', '/mozilla.org/20050126_1/Europe/Berlin')[0] ?? null);
        $this->assertSame(-900, DateUtil::parseDuration('-PT15M'));
        $this->assertSame(694800, DateUtil::parseDuration('P1W1DT1H'));
    }
}

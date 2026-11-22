<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Calendar\Application;

use Foresi\Calendar\Application\IcsWriter;
use Foresi\Calendar\Contract\CalendarEntry;
use PHPUnit\Framework\TestCase;

/**
 * The feed as RFC 5545 text: escaped, folded at 75 octets, every time in UTC and
 * a date-only entry all day (review focus 4 of plan 32).
 */
final class IcsWriterTest extends TestCase
{
    public function testEscapesCommaSemicolonBackslashNewline(): void
    {
        $ics = $this->written($this->entry("Call: Novák, s.r.o.; back\\slash\r\nnext line\nthird"));

        self::assertContains('SUMMARY:Call: Novák\, s.r.o.\; back\\\\slash\nnext line\nthird', $this->unfolded($ics));
    }

    public function testEscapesTheCalendarName(): void
    {
        self::assertContains('X-WR-CALNAME:Work\, sales\; mine', $this->unfolded(new IcsWriter()->write('Work, sales; mine', [])));
    }

    public function testFoldsLongUtf8LinesWithoutSplittingACharacter(): void
    {
        $title = str_repeat('Žluťoučký kůň úpěl ďábelské ódy. ', 8);
        $ics = $this->written($this->entry($title));

        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(75, \strlen($line), $line);
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), 'A folded line splits a character: '.bin2hex($line));
        }
        self::assertContains('SUMMARY:'.$title, $this->unfolded($ics));
        self::assertStringContainsString("\r\n ", $ics);
    }

    public function testALoneCarriageReturnIsALineBreakAndControlCharactersGo(): void
    {
        $ics = $this->written($this->entry("one\rtwo\x00\x07\tthree\x1F\x7Ffour"));

        self::assertContains('SUMMARY:one\ntwothreefour', $this->unfolded($ics));
    }

    public function testALineOf75OctetsStaysWholeAnd76Fold(): void
    {
        $exact = $this->written($this->entry(str_repeat('a', 75 - \strlen('SUMMARY:'))));
        $over = $this->written($this->entry(str_repeat('a', 76 - \strlen('SUMMARY:'))));

        self::assertStringContainsString("\r\nSUMMARY:".str_repeat('a', 67)."\r\n", $exact);
        self::assertStringContainsString("\r\nSUMMARY:".str_repeat('a', 67)."\r\n a\r\n", $over);
    }

    public function testFoldsAtEveryOffsetWithoutSplittingACharacter(): void
    {
        for ($offset = 0; $offset < 4; ++$offset) {
            $title = str_repeat('x', $offset).str_repeat('ěš€', 40);
            $ics = $this->written($this->entry($title));

            foreach (explode("\r\n", $ics) as $line) {
                self::assertLessThanOrEqual(75, \strlen($line));
                self::assertTrue(mb_check_encoding($line, 'UTF-8'), 'Offset '.$offset.' splits a character.');
            }
            $summary = array_values(array_filter($this->unfolded($ics), static fn (string $line): bool => str_starts_with($line, 'SUMMARY:')));
            self::assertSame(['SUMMARY:'.$title], $summary);
            // Each line is filled: the next character would not fit.
            $lines = explode("\r\n", substr($ics, (int) strpos($ics, 'SUMMARY:')));
            self::assertGreaterThan(72, \strlen($lines[0]));
        }
    }

    public function testEndsEveryLineWithCrlf(): void
    {
        $ics = $this->written($this->entry('Meeting'));

        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        self::assertDoesNotMatchRegularExpression("/(?<!\r)\n/", $ics);
    }

    public function testAllDayUsesValueDateAndNextDayEnd(): void
    {
        $lines = $this->unfolded($this->written(new CalendarEntry('task-1@foresi.example.com', 'Send the offer', new \DateTimeImmutable('2026-12-03', new \DateTimeZone('Europe/Prague')), null, true, new \DateTimeImmutable('2026-11-20 08:00:00 UTC'), null)));

        self::assertContains('DTSTART;VALUE=DATE:20261203', $lines);
        self::assertContains('DTEND;VALUE=DATE:20261204', $lines);
    }

    public function testAllDayOverSeveralDaysEndsTheDayAfterTheLast(): void
    {
        $prague = new \DateTimeZone('Europe/Prague');
        $lines = $this->unfolded($this->written(new CalendarEntry('trip-1@foresi.example.com', 'Trade fair', new \DateTimeImmutable('2026-12-30', $prague), new \DateTimeImmutable('2027-01-01', $prague), true, new \DateTimeImmutable('2026-11-20 08:00:00 UTC'), null)));

        self::assertContains('DTSTART;VALUE=DATE:20261230', $lines);
        self::assertContains('DTEND;VALUE=DATE:20270102', $lines);
    }

    public function testDstChangeKeepsPragueWallClockInUtc(): void
    {
        $prague = new \DateTimeZone('Europe/Prague');
        $before = new CalendarEntry('a@foresi.example.com', 'Before', new \DateTimeImmutable('2026-10-24 10:00', $prague), new \DateTimeImmutable('2026-10-24 11:00', $prague), false, new \DateTimeImmutable('2026-10-01 00:00 UTC'), null);
        $after = new CalendarEntry('b@foresi.example.com', 'After', new \DateTimeImmutable('2026-10-26 10:00', $prague), new \DateTimeImmutable('2026-10-26 11:00', $prague), false, new \DateTimeImmutable('2026-10-01 00:00 UTC'), null);
        $across = new CalendarEntry('c@foresi.example.com', 'Across', new \DateTimeImmutable('2026-10-25 01:30', $prague), new \DateTimeImmutable('2026-10-25 04:30', $prague), false, new \DateTimeImmutable('2026-10-01 00:00 UTC'), null);

        $lines = $this->unfolded(new IcsWriter()->write('Mine', [$before, $after, $across]));

        self::assertContains('DTSTART:20261024T080000Z', $lines);
        self::assertContains('DTEND:20261024T090000Z', $lines);
        self::assertContains('DTSTART:20261026T090000Z', $lines);
        self::assertContains('DTEND:20261026T100000Z', $lines);
        // 01:30 summer time to 04:30 winter time: four hours, not three.
        self::assertContains('DTSTART:20261024T233000Z', $lines);
        self::assertContains('DTEND:20261025T033000Z', $lines);
    }

    public function testATimedEntryWithoutAnEndHasNoDtend(): void
    {
        $lines = $this->unfolded($this->written(new CalendarEntry('x@foresi.example.com', 'Reminder', new \DateTimeImmutable('2026-12-01 09:15 UTC'), null, false, new \DateTimeImmutable('2026-11-20 08:00:00 UTC'), null)));

        self::assertContains('DTSTART:20261201T091500Z', $lines);
        self::assertSame([], array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'DTEND'))));
    }

    public function testAnEventHasUidStampSummaryAndUrlOnly(): void
    {
        $entry = new CalendarEntry('activities.planned-0190@foresi.example.com', 'Meeting', new \DateTimeImmutable('2026-12-01 09:00 UTC'), new \DateTimeImmutable('2026-12-01 10:00 UTC'), false, new \DateTimeImmutable('2026-11-20 08:30:15', new \DateTimeZone('Europe/Prague')), 'https://foresi.example.com/activities/0190');

        $lines = $this->unfolded($this->written($entry));

        $event = \array_slice($lines, (int) array_search('BEGIN:VEVENT', $lines, true), 8);
        self::assertSame([
            'BEGIN:VEVENT',
            'UID:activities.planned-0190@foresi.example.com',
            'DTSTAMP:20261120T073015Z',
            'DTSTART:20261201T090000Z',
            'DTEND:20261201T100000Z',
            'SUMMARY:Meeting',
            'URL:https://foresi.example.com/activities/0190',
            'END:VEVENT',
        ], $event);
    }

    public function testCalendarHasProdidNameAndRefreshInterval(): void
    {
        $lines = $this->unfolded(new IcsWriter()->write('Martin at work', []));

        self::assertSame(['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Foresi//Calendar//EN'], \array_slice($lines, 0, 3));
        self::assertContains('CALSCALE:GREGORIAN', $lines);
        self::assertContains('METHOD:PUBLISH', $lines);
        self::assertContains('X-WR-CALNAME:Martin at work', $lines);
        self::assertContains('NAME:Martin at work', $lines);
        self::assertContains('REFRESH-INTERVAL;VALUE=DURATION:PT3H', $lines);
        self::assertContains('X-PUBLISHED-TTL:PT3H', $lines);
        self::assertSame('END:VCALENDAR', $lines[array_key_last($lines)]);
        self::assertNotContains('BEGIN:VEVENT', $lines);
    }

    private function entry(string $title): CalendarEntry
    {
        return new CalendarEntry('e-1@foresi.example.com', $title, new \DateTimeImmutable('2026-12-01 09:00 UTC'), new \DateTimeImmutable('2026-12-01 10:00 UTC'), false, new \DateTimeImmutable('2026-11-20 08:00:00 UTC'), null);
    }

    private function written(CalendarEntry $entry): string
    {
        return new IcsWriter()->write('Mine', [$entry]);
    }

    /**
     * @return list<string> the content lines, unfolded
     */
    private function unfolded(string $ics): array
    {
        return array_values(array_filter(explode("\r\n", str_replace("\r\n ", '', $ics)), static fn (string $line): bool => '' !== $line));
    }
}

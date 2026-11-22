<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Application;

use Foresi\Calendar\Contract\CalendarEntry;

/**
 * A minimal writer of RFC 5545 calendars, enough for a read-only feed: lines end
 * with CRLF and are folded at 75 octets without splitting a UTF-8 character, TEXT
 * values are escaped (3.3.11), every time is in UTC and an all-day entry is a DATE
 * that ends the day after its last (3.6.1). The calendar is named (X-WR-CALNAME,
 * which Apple Calendar and Google Calendar read, and NAME of RFC 7986) and asks to
 * be fetched again every three hours (REFRESH-INTERVAL of RFC 7986, and
 * X-PUBLISHED-TTL, which Outlook and Apple Calendar read). Nothing here needs a
 * library: sabre/vobject would parse and validate whole calendars, and a feed only
 * writes a few properties.
 */
final readonly class IcsWriter
{
    private const string CRLF = "\r\n";
    private const int LINE_OCTETS = 75;
    private const string REFRESH = 'PT3H';

    /**
     * @param iterable<CalendarEntry> $entries
     */
    public function write(string $name, iterable $entries): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Foresi//Calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->text($name),
            'NAME:'.$this->text($name),
            'REFRESH-INTERVAL;VALUE=DURATION:'.self::REFRESH,
            'X-PUBLISHED-TTL:'.self::REFRESH,
        ];
        foreach ($entries as $entry) {
            array_push($lines, ...$this->event($entry));
        }
        $lines[] = 'END:VCALENDAR';

        return implode('', array_map(static fn (string $line): string => self::fold($line).self::CRLF, $lines));
    }

    /**
     * @return list<string>
     */
    private function event(CalendarEntry $entry): array
    {
        $lines = ['BEGIN:VEVENT', 'UID:'.$this->text($entry->uid), 'DTSTAMP:'.$this->utc($entry->lastModified)];
        if ($entry->allDay) {
            $lines[] = 'DTSTART;VALUE=DATE:'.$entry->start->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.($entry->end ?? $entry->start)->modify('+1 day')->format('Ymd');
        } else {
            $lines[] = 'DTSTART:'.$this->utc($entry->start);
            if ($entry->end instanceof \DateTimeImmutable) {
                $lines[] = 'DTEND:'.$this->utc($entry->end);
            }
        }
        $lines[] = 'SUMMARY:'.$this->text($entry->title);
        if (null !== $entry->url) {
            $lines[] = 'URL:'.$entry->url;
        }
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    private function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * A TEXT value (RFC 5545 3.3.11): backslash, semicolon and comma escaped, any
     * line break as \n, other control characters dropped.
     */
    private function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = str_replace([...array_map(\chr(...), [...range(0, 9), ...range(11, 31)]), "\x7F"], '', $value);

        return strtr($value, ['\\' => '\\\\', ';' => '\;', ',' => '\,', "\n" => '\n']);
    }

    /**
     * Folds a content line at 75 octets (RFC 5545 3.1): each further line starts
     * with a space, and a multi-octet character stays whole.
     */
    private static function fold(string $line): string
    {
        $folded = '';
        $current = '';
        $limit = self::LINE_OCTETS;
        foreach (mb_str_split($line, 1, 'UTF-8') as $character) {
            if (\strlen($current) + \strlen($character) > $limit) {
                $folded .= $current.self::CRLF;
                $current = ' ';
                $limit = self::LINE_OCTETS;
            }
            $current .= $character;
        }

        return $folded.$current;
    }
}

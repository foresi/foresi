<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Country\CzechRepublic\ExchangeRate;

use App\ExchangeRate\Application\PublishedRates;
use App\ExchangeRate\Application\Quote;

/**
 * Reads the daily fix file of the ČNB: a first line with the date and the number
 * of the publication ("10.11.2026 #216"), a header line, then one line per
 * currency, "country|currency|amount|code|rate", with a decimal comma.
 */
final class CnbDailyFix
{
    /**
     * @throws \UnexpectedValueException the text is not a daily fix file
     */
    public static function parse(string $text): PublishedRates
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($text));
        if (false === $lines || \count($lines) < 3) {
            throw new \UnexpectedValueException('The daily fix has no rates.');
        }

        if (1 !== preg_match('/^(\d{2}\.\d{2}\.\d{4}) #\d+\z/', $lines[0], $header)) {
            throw new \UnexpectedValueException('The first line of the daily fix is not a date and a number.');
        }
        $publishedOn = \DateTimeImmutable::createFromFormat('!d.m.Y', $header[1], new \DateTimeZone('Europe/Prague'));
        if (!$publishedOn instanceof \DateTimeImmutable || $publishedOn->format('d.m.Y') !== $header[1]) {
            throw new \UnexpectedValueException('The date of the daily fix is not a day.');
        }
        if (!str_ends_with($lines[1], '|kód|kurz')) {
            throw new \UnexpectedValueException('The column line of the daily fix is not the one known.');
        }

        $quotes = [];
        foreach (\array_slice($lines, 2) as $line) {
            $fields = explode('|', $line);
            if (5 !== \count($fields) || 1 !== preg_match('/^[1-9]\d*\z/', $fields[2]) || 1 !== preg_match('/^[A-Z]{3}\z/', $fields[3]) || 1 !== preg_match('/^\d+(,\d+)?\z/', $fields[4])) {
                throw new \UnexpectedValueException('A line of the daily fix is not a rate.');
            }
            $quotes[$fields[3]] = new Quote($fields[3], (int) $fields[2], str_replace(',', '.', $fields[4]));
        }

        return new PublishedRates($publishedOn, $quotes);
    }
}

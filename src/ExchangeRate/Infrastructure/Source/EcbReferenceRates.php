<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Infrastructure\Source;

use Foresi\ExchangeRate\Contract\PublishedRates;
use Foresi\ExchangeRate\Contract\Quote;

/**
 * Reads a eurofxref file of the European Central Bank, the daily one or the
 * history of 90 days: one Cube per day with its time, and in it one Cube per
 * currency with its rate per 1 EUR ("USD" "1.0935": 1 EUR is 1.0935 USD).
 * Each rate is kept without rounding as what that many units cost in EUR:
 * "1.0935" is 10935 USD for 10000 EUR.
 */
final class EcbReferenceRates
{
    public const string NAMESPACE = 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref';
    public const string BASE = 'EUR';
    /** The most digits of a rate, so its units fit the integer amount of a quote. */
    private const int MAX_DIGITS = 9;
    /** The most decimals of a rate, the scale of the stored rates. */
    private const int MAX_DECIMALS = 6;

    /**
     * @return array<string, PublishedRates> by the day published as Y-m-d, newest first
     *
     * @throws \UnexpectedValueException the text is not a eurofxref file
     */
    public static function parse(string $xml): array
    {
        $document = new \DOMDocument();
        $errors = libxml_use_internal_errors(true);
        try {
            $loaded = '' !== trim($xml) && $document->loadXML($xml, \LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($errors);
        }
        if (!$loaded || 'Envelope' !== $document->documentElement?->localName) {
            throw new \UnexpectedValueException('The reference rates are not a eurofxref file.');
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('e', self::NAMESPACE);
        $days = $xpath->query('/*/e:Cube/e:Cube');
        if (false === $days || 0 === $days->length) {
            throw new \UnexpectedValueException('The reference rates have no day.');
        }

        $published = [];
        foreach ($days as $day) {
            \assert($day instanceof \DOMElement);
            $published += self::day($xpath, $day);
        }
        krsort($published);

        return $published;
    }

    /**
     * @return array<string, PublishedRates>
     */
    private static function day(\DOMXPath $xpath, \DOMElement $day): array
    {
        $time = $day->getAttribute('time');
        $publishedOn = \DateTimeImmutable::createFromFormat('!Y-m-d', $time, new \DateTimeZone('Europe/Berlin'));
        if (!$publishedOn instanceof \DateTimeImmutable || $publishedOn->format('Y-m-d') !== $time) {
            throw new \UnexpectedValueException('A day of the reference rates is not a date.');
        }

        $quotes = [];
        $rates = $xpath->query('e:Cube', $day);
        foreach (false === $rates ? [] : $rates as $rate) {
            \assert($rate instanceof \DOMElement);
            $quote = self::quote($rate->getAttribute('currency'), $rate->getAttribute('rate'));
            $quotes[$quote->currency] = $quote;
        }
        if ([] === $quotes) {
            throw new \UnexpectedValueException('A day of the reference rates has no rates.');
        }

        return [$time => new PublishedRates($publishedOn, $quotes, self::BASE)];
    }

    /**
     * "1.0935" USD per 1 EUR as 10935 USD costing 10000 EUR.
     */
    private static function quote(string $currency, string $rate): Quote
    {
        if (1 !== preg_match('/^[A-Z]{3}\z/', $currency) || self::BASE === $currency) {
            throw new \UnexpectedValueException('A currency of the reference rates is not a code.');
        }
        if (1 !== preg_match('/^(\d+)(?:\.(\d+))?\z/', Quote::normalise($rate), $parts)) {
            throw new \UnexpectedValueException('A rate of the reference rates is not a decimal number.');
        }
        $decimals = $parts[2] ?? '';
        $digits = ltrim($parts[1].$decimals, '0');
        if ('' === $digits || \strlen($digits) > self::MAX_DIGITS || \strlen($decimals) > self::MAX_DECIMALS) {
            throw new \UnexpectedValueException('A rate of the reference rates is zero or has too many digits.');
        }

        return new Quote($currency, (int) $digits, '1'.str_repeat('0', \strlen($decimals)));
    }
}

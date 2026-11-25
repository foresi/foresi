<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Infrastructure\Source;

use Foresi\ExchangeRate\Contract\PublishedRates;
use Foresi\ExchangeRate\Contract\RateSource;
use Foresi\Shared\Infrastructure\Registry\PublicRegistryClient;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The euro foreign exchange reference rates of the European Central Bank,
 * the neutral source that needs no country module. The bank publishes about
 * 30 currencies per 1 EUR, CZK among them, on TARGET working days around
 * 16:00 CET; the rates may be reused with the ECB cited as their source and
 * are published for information. Today is read from the daily file, an
 * earlier date from the history of the last 90 days, each answered with the
 * last publication on or before the date; a date before those 90 days has no
 * rate here. It comes after every other installed source, so a country's own
 * source stays the default without a choice.
 */
#[AsTaggedItem(priority: -100)]
final readonly class EcbRateSource implements RateSource
{
    public const string NAME = 'ecb';
    public const string DAILY_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';
    public const string HISTORY_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml';

    public function __construct(private PublicRegistryClient $client, private ClockInterface $clock)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function zone(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Berlin');
    }

    public function expectedAt(): string
    {
        return '16:10';
    }

    public function settledAt(): string
    {
        return '17:00';
    }

    public function fetch(\DateTimeImmutable $date): ?PublishedRates
    {
        $day = $date->format('Y-m-d');
        $today = $this->clock->now()->setTimezone($this->zone())->format('Y-m-d');
        $answer = $this->client->request(self::NAME, 'GET', $day >= $today ? self::DAILY_URL : self::HISTORY_URL);
        if (null === $answer) {
            return null;
        }

        try {
            $publications = EcbReferenceRates::parse($answer['body']);
        } catch (\UnexpectedValueException $unreadable) {
            $this->client->fail(self::NAME, $unreadable->getMessage());

            return null;
        }
        foreach ($publications as $publishedOn => $published) {
            if ($publishedOn <= $day) {
                return $published;
            }
        }
        $this->client->fail(self::NAME, 'the date is before the first day of the reference rates');

        return null;
    }
}

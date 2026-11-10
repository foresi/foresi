<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Country\CzechRepublic\ExchangeRate;

use App\ExchangeRate\Application\PublishedRates;
use App\ExchangeRate\Application\RateSource;
use App\Shared\Infrastructure\Registry\PublicRegistryClient;

/**
 * The daily fix of the Czech National Bank. The bank publishes on business days
 * around 14:30, Prague time, and answers a date with the last publication on or
 * before it, so a weekend, a holiday and the hours before the publication get
 * the previous day's file; its first line says which day that was.
 */
final readonly class CnbRateSource implements RateSource
{
    public const string NAME = 'cnb';
    public const string URL = 'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt';

    public function __construct(private PublicRegistryClient $client)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function zone(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Prague');
    }

    public function expectedAt(): string
    {
        return '14:35';
    }

    public function settledAt(): string
    {
        return '15:00';
    }

    public function fetch(\DateTimeImmutable $date): ?PublishedRates
    {
        $answer = $this->client->request(self::NAME, 'GET', self::URL.'?date='.$date->format('d.m.Y'));
        if (null === $answer) {
            return null;
        }

        try {
            return CnbDailyFix::parse($answer['body']);
        } catch (\UnexpectedValueException $unreadable) {
            $this->client->fail(self::NAME, $unreadable->getMessage());

            return null;
        }
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Infrastructure;

use Foresi\ExchangeRate\Contract\PublishedRates;
use Foresi\ExchangeRate\Contract\RateSource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The rate source an installed module offers, such as the ČNB from the Czech
 * country module. With none installed it is no source: its publication time
 * never comes, so the daily task never fetches, and a fetch finds nothing.
 * One source is in use; were several installed, the first would answer.
 */
final readonly class InstalledRateSource implements RateSource
{
    public const string NONE = 'none';
    /** Later than any time of day, as H:i compares. */
    private const string NEVER = '24:00';

    private ?RateSource $source;

    /**
     * @param iterable<RateSource> $sources
     */
    public function __construct(
        #[AutowireIterator(RateSource::TAG)]
        iterable $sources,
    ) {
        $first = null;
        foreach ($sources as $source) {
            $first = $source;
            break;
        }
        $this->source = $first;
    }

    public function name(): string
    {
        return $this->source?->name() ?? self::NONE;
    }

    public function zone(): \DateTimeZone
    {
        return $this->source?->zone() ?? new \DateTimeZone('UTC');
    }

    public function expectedAt(): string
    {
        return $this->source?->expectedAt() ?? self::NEVER;
    }

    public function settledAt(): string
    {
        return $this->source?->settledAt() ?? self::NEVER;
    }

    public function fetch(\DateTimeImmutable $date): ?PublishedRates
    {
        return $this->source?->fetch($date);
    }
}

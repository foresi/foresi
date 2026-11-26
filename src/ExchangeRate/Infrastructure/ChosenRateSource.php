<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Infrastructure;

use Foresi\ExchangeRate\Application\RateSources;
use Foresi\ExchangeRate\Contract\PublishedRates;
use Foresi\ExchangeRate\Contract\RateSource;

/**
 * The source the firm converts at (RateSources::current()): the one it
 * chose, else its country's default, else the first installed. With none
 * installed it is no source: its publication time never comes, so the daily
 * task never fetches, and a fetch finds nothing.
 */
final readonly class ChosenRateSource implements RateSource
{
    public const string NONE = 'none';
    /** Later than any time of day, as H:i compares. */
    private const string NEVER = '24:00';

    public function __construct(private RateSources $sources)
    {
    }

    public function name(): string
    {
        return $this->sources->current()?->name() ?? self::NONE;
    }

    public function zone(): \DateTimeZone
    {
        return $this->sources->current()?->zone() ?? new \DateTimeZone('UTC');
    }

    public function expectedAt(): string
    {
        return $this->sources->current()?->expectedAt() ?? self::NEVER;
    }

    public function settledAt(): string
    {
        return $this->sources->current()?->settledAt() ?? self::NEVER;
    }

    public function fetch(\DateTimeImmutable $date): ?PublishedRates
    {
        return $this->sources->current()?->fetch($date);
    }
}

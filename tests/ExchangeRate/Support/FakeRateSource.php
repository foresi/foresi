<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\ExchangeRate\Support;

use App\ExchangeRate\Application\PublishedRates;
use App\ExchangeRate\Application\Quote;
use App\ExchangeRate\Application\RateSource;
use Psr\Clock\ClockInterface;

/**
 * Answers a date with the last publication on or before it, as the ČNB does.
 * With a clock, a publication is out from 14:30 of its day on.
 */
final class FakeRateSource implements RateSource
{
    /** @var list<string> the dates asked for, as Y-m-d */
    public array $asked = [];

    public bool $down = false;

    /** @var array<string, PublishedRates> by day published */
    private array $publications = [];

    public function __construct(private readonly ?ClockInterface $clock = null)
    {
        $this->publish('2026-11-06', '24.300', '14.991');
        $this->publish('2026-11-10', '24.335', '14.981');
    }

    public function publish(string $day, string $euro, string $dollar): void
    {
        $this->publications[$day] = new PublishedRates(new \DateTimeImmutable($day), [
            'EUR' => new Quote('EUR', 1, $euro),
            'JPY' => new Quote('JPY', 100, $dollar),
        ]);
        ksort($this->publications);
    }

    public function name(): string
    {
        return 'fake';
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

    /**
     * A publication is out from 14:30 of its day.
     */
    private function isOut(string $day): bool
    {
        return !$this->clock instanceof ClockInterface
            || $this->clock->now()->setTimezone($this->zone()) >= new \DateTimeImmutable($day.' 14:30:00', $this->zone());
    }

    public function fetch(\DateTimeImmutable $date): ?PublishedRates
    {
        $this->asked[] = $date->format('Y-m-d');
        if ($this->down) {
            return null;
        }

        $found = null;
        foreach ($this->publications as $day => $published) {
            if ($day <= $date->format('Y-m-d') && $this->isOut($day)) {
                $found = $published;
            }
        }

        return $found;
    }
}

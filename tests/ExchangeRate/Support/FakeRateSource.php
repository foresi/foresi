<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ExchangeRate\Support;

use Foresi\ExchangeRate\Contract\PublishedRates;
use Foresi\ExchangeRate\Contract\Quote;
use Foresi\ExchangeRate\Contract\RateSource;
use Psr\Clock\ClockInterface;

/**
 * Answers a date with the last publication on or before it, as the ČNB does.
 * With a clock, a publication is out from 14:30 of its day on. Priced in CZK
 * it quotes EUR and JPY per 100 as the ČNB does; priced in EUR it quotes per
 * 1 EUR as the ECB does, CZK, USD, JPY and IDR, written as whole units.
 */
final class FakeRateSource implements RateSource
{
    /** @var list<string> the dates asked for, as Y-m-d */
    public array $asked = [];

    public bool $down = false;

    /** @var array<string, PublishedRates> by day published */
    private array $publications = [];

    public function __construct(private readonly ?ClockInterface $clock = null, private readonly string $base = 'CZK', private readonly string $name = '')
    {
        if ('CZK' === $base) {
            $this->publish('2026-11-06', '24.300', '14.991');
            $this->publish('2026-11-10', '24.335', '14.981');
        } else {
            // 1 EUR is 24.3 CZK and 1.08 USD; then 24.435 CZK, 1.0935 USD, 174.22 JPY and 20055.72 IDR.
            $this->publishPerEuro('2026-11-06', ['CZK' => [243, '10'], 'USD' => [108, '100']]);
            $this->publishPerEuro('2026-11-10', ['CZK' => [24435, '1000'], 'USD' => [10935, '10000'], 'JPY' => [17422, '100'], 'IDR' => [2005572, '100']]);
        }
    }

    public function publish(string $day, string $euro, string $dollar): void
    {
        $this->publications[$day] = new PublishedRates(new \DateTimeImmutable($day), [
            'EUR' => new Quote('EUR', 1, $euro),
            'JPY' => new Quote('JPY', 100, $dollar),
        ], 'CZK');
        ksort($this->publications);
    }

    /**
     * @param array<string, array{int, string}> $quotes units of the currency for that many EUR
     */
    public function publishPerEuro(string $day, array $quotes): void
    {
        $built = [];
        foreach ($quotes as $code => [$amount, $rate]) {
            $built[$code] = new Quote($code, $amount, $rate);
        }
        $this->publications[$day] = new PublishedRates(new \DateTimeImmutable($day), $built, 'EUR');
        ksort($this->publications);
    }

    public function name(): string
    {
        return '' !== $this->name ? $this->name : ('CZK' === $this->base ? 'fake' : 'fake_eur');
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

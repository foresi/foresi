<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ExchangeRate\Application;

use Psr\Clock\ClockInterface;

/**
 * Fetches what the source published for a date and stores it. The rates are kept
 * under the day they were published, and under the date asked for as well when
 * that is another day (a weekend, a holiday), so the next ask for it is a read.
 * That second copy is kept only once the date cannot change any more: today's
 * answer before the publication is the previous day's and the real one comes
 * later.
 */
final readonly class FetchRates
{
    public function __construct(
        private RateSource $source,
        private RateStore $store,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return PublishedRates|null null when the source gave no usable answer; nothing is stored then
     */
    public function fetch(\DateTimeImmutable $date): ?PublishedRates
    {
        $published = $this->source->fetch($date);
        if (!$published instanceof PublishedRates) {
            return null;
        }

        $this->store->save($published->publishedOn, $published, $this->source->name());
        if ($date->format('Y-m-d') !== $published->publishedOn->format('Y-m-d') && $this->isSettled($date)) {
            $this->store->save($date, $published, $this->source->name());
        }

        return $published;
    }

    private function isSettled(\DateTimeImmutable $date): bool
    {
        $now = $this->clock->now()->setTimezone($this->source->zone());
        $today = $now->format('Y-m-d');
        $day = $date->format('Y-m-d');

        return $day < $today || $day === $today && $now->format('H:i') >= $this->source->settledAt();
    }
}

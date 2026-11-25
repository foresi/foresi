<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Application;

use Foresi\ExchangeRate\Contract\PublishedRates;
use Foresi\ExchangeRate\Contract\RateSource;
use Psr\Clock\ClockInterface;

/**
 * Fetches what a source published for a date and stores it under the source's
 * name, with its base. The rates are kept under the day they were published,
 * and under the date asked for as well when that is another day (a weekend, a
 * holiday), so the next ask for it is a read. That second copy is kept only
 * once the date cannot change any more: today's answer before the publication
 * is the previous day's and the real one comes later.
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
     * From the source the firm converts at.
     *
     * @return PublishedRates|null null when the source gave no usable answer; nothing is stored then
     */
    public function fetch(\DateTimeImmutable $date): ?PublishedRates
    {
        return $this->fetchFrom($this->source, $date);
    }

    /**
     * From any installed source, such as one fetched by hand.
     *
     * @return PublishedRates|null null when the source gave no usable answer; nothing is stored then
     */
    public function fetchFrom(RateSource $source, \DateTimeImmutable $date): ?PublishedRates
    {
        $published = $source->fetch($date);
        if (!$published instanceof PublishedRates) {
            return null;
        }

        $name = $source->name();
        $this->store->save($published->publishedOn, $published, $name);
        if ($date->format('Y-m-d') !== $published->publishedOn->format('Y-m-d') && $this->isSettled($source, $date)) {
            $this->store->save($date, $published, $name);
        }

        return $published;
    }

    private function isSettled(RateSource $source, \DateTimeImmutable $date): bool
    {
        $now = $this->clock->now()->setTimezone($source->zone());
        $today = $now->format('Y-m-d');
        $day = $date->format('Y-m-d');

        return $day < $today || $day === $today && $now->format('H:i') >= $source->settledAt();
    }
}

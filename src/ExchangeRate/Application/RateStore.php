<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Application;

use Foresi\ExchangeRate\Contract\PublishedRates;

/**
 * The quotes kept, global to the installation, per source: two sources keep
 * their quotes of the same day side by side, each in its own base.
 */
interface RateStore
{
    /**
     * The stored quotes of the source for the date among these currencies,
     * with the base and the day they were published; null when none of them
     * is stored.
     *
     * @param list<string> $currencies
     */
    public function find(string $source, \DateTimeImmutable $date, array $currencies): ?PublishedRates;

    /**
     * Whether any quote of the source is stored for the date.
     */
    public function has(string $source, \DateTimeImmutable $date): bool;

    /**
     * Keeps the quotes as those of the source on $validOn; what is stored already stays.
     */
    public function save(\DateTimeImmutable $validOn, PublishedRates $published, string $source): void;
}

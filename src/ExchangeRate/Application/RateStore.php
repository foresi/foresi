<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ExchangeRate\Application;

use App\ExchangeRate\Domain\ExchangeRate;

/**
 * The rates kept, global to the installation.
 */
interface RateStore
{
    public function find(string $currency, \DateTimeImmutable $date): ?ExchangeRate;

    /**
     * Whether any rate is stored for the date.
     */
    public function has(\DateTimeImmutable $date): bool;

    /**
     * Keeps the quotes as the rates of $validOn; what is stored already stays.
     */
    public function save(\DateTimeImmutable $validOn, PublishedRates $published, string $source): void;
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ExchangeRate\Application;

/**
 * Everything a source published on one day.
 */
final readonly class PublishedRates
{
    /**
     * @param array<string, Quote> $quotes by currency code
     */
    public function __construct(
        public \DateTimeImmutable $publishedOn,
        public array $quotes,
    ) {
    }
}

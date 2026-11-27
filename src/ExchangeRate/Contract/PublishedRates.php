<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Contract;

/**
 * Everything a source published on one day, priced in its base currency: each
 * quote says what an amount of a currency costs in the base. The ČNB prices in
 * CZK; the ECB in EUR, so Foresi converts to CZK through the euro.
 */
final readonly class PublishedRates
{
    /** The base of a source that names none. */
    private const string DEFAULT_BASE = 'CZK';

    /** The ISO 4217 code of the currency the quotes are priced in, upper case. */
    public string $base;

    /**
     * @param array<string, Quote> $quotes by currency code
     * @param string|null          $base   the currency the quotes are priced in, such as "CZK" or "EUR";
     *                                     leaving it out is deprecated since module API 1.1, removed in 3.0, use "CZK" instead
     */
    public function __construct(
        public \DateTimeImmutable $publishedOn,
        public array $quotes,
        ?string $base = null,
    ) {
        if (null === $base) {
            trigger_deprecation('foresi/exchange-rate', '0.11', 'Creating "%s" without a base currency is deprecated since module API 1.1, pass "%s" as the base.', self::class, self::DEFAULT_BASE);
            $base = self::DEFAULT_BASE;
        }
        if (1 !== preg_match('/^[A-Z]{3}\z/', $base)) {
            throw new \InvalidArgumentException('The base of published rates is an ISO 4217 code in upper case.');
        }
        $this->base = $base;
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Contract\BankingCountry;

/**
 * Finds the banking customs of a country among the tagged country modules.
 */
final readonly class BankingCountries
{
    /**
     * @param iterable<BankingCountry> $countries
     */
    public function __construct(private iterable $countries)
    {
    }

    /**
     * The customs of the country, or null when no installed module knows it.
     */
    public function for(string $countryCode): ?BankingCountry
    {
        foreach ($this->countries as $country) {
            if ($country->supports($countryCode)) {
                return $country;
            }
        }

        return null;
    }
}

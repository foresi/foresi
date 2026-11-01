<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Finds the invoicing rules of a country among the tagged country modules.
 */
final readonly class InvoicingCountries
{
    /**
     * @param iterable<InvoicingCountry> $countries
     */
    public function __construct(private iterable $countries)
    {
    }

    /**
     * @throws UnsupportedCountry
     */
    public function for(string $countryCode): InvoicingCountry
    {
        foreach ($this->countries as $country) {
            if ($country->supports($countryCode)) {
                return $country;
            }
        }

        throw UnsupportedCountry::for($countryCode);
    }
}

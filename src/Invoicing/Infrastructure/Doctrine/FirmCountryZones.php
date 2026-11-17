<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Instance\Application\TenantZoneFallback;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\UnsupportedCountry;
use Doctrine\DBAL\Connection;

/**
 * The zone of a tenant without one stored: that of the country of its first
 * active firm, the oldest by its time-ordered id, as the country's invoicing
 * rules name it. One query for all tenants, on the connection, since the tick
 * that asks has no tenant active. A tenant without an active firm, or whose firm
 * is in a country without rules, is left out.
 */
final readonly class FirmCountryZones implements TenantZoneFallback
{
    public function __construct(
        private Connection $connection,
        private InvoicingCountries $countries,
    ) {
    }

    public function zonesFor(array $tenants): array
    {
        if ([] === $tenants) {
            return [];
        }

        // The firms belong to the one customer of the instance, so every tenant takes the country of the first one.
        $code = $this->connection->fetchOne('SELECT address_country_code FROM legal_entity WHERE archived_at IS NULL ORDER BY id LIMIT 1');
        if (!\is_string($code)) {
            return [];
        }
        $country = trim($code);
        $countries = [];
        foreach ($tenants as $tenant) {
            $countries[$tenant->toString()] = $country;
        }

        $zones = [];
        foreach ($countries as $tenant => $country) {
            try {
                $zones[$tenant] = $this->countries->for($country)->timezone();
            } catch (UnsupportedCountry) {
                // No rules, no zone: the directory falls back to UTC.
            }
        }

        return $zones;
    }
}

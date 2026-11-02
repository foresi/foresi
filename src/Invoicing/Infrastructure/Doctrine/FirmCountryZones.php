<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\UnsupportedCountry;
use App\Tenancy\Application\TenantZoneFallback;
use App\Tenancy\Domain\TenantId;
use Doctrine\DBAL\ArrayParameterType;
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

        /** @var list<array{tenant_id: string, address_country_code: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT tenant_id, address_country_code FROM legal_entity WHERE archived_at IS NULL AND tenant_id IN (:tenants) ORDER BY tenant_id, id',
            ['tenants' => array_map(static fn (TenantId $tenant): string => $tenant->toString(), $tenants)],
            ['tenants' => ArrayParameterType::STRING],
        );

        $countries = [];
        foreach ($rows as $row) {
            $countries[$row['tenant_id']] ??= trim($row['address_country_code']);
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

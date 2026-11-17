<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Instance\Application\ZoneFallback;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\UnsupportedCountry;
use Doctrine\DBAL\Connection;

/**
 * The zone of the instance without APP_TIMEZONE: that of the country of its
 * first active firm, the oldest by its time-ordered id, as the country's
 * invoicing rules name it. Null without an active firm, or for a firm in a
 * country without rules.
 */
final readonly class FirmCountryZones implements ZoneFallback
{
    public function __construct(
        private Connection $connection,
        private InvoicingCountries $countries,
    ) {
    }

    public function zone(): ?\DateTimeZone
    {
        $code = $this->connection->fetchOne('SELECT address_country_code FROM legal_entity WHERE archived_at IS NULL ORDER BY id LIMIT 1');
        if (!\is_string($code)) {
            return null;
        }

        try {
            return $this->countries->for(trim($code))->timezone();
        } catch (UnsupportedCountry) {
            return null;
        }
    }
}

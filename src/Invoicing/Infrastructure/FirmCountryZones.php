<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure;

use Foresi\Instance\Application\ZoneFallback;
use Foresi\Invoicing\Application\InvoicingCountries;
use Foresi\Invoicing\Application\UnsupportedCountry;
use Foresi\Party\Application\InstanceFirm;

/**
 * The zone of the instance without APP_TIMEZONE: that of the country of its
 * firm (ADR 0039), as the country's invoicing rules name it. Null before the
 * first-run setup, or for a firm in a country without rules.
 */
final readonly class FirmCountryZones implements ZoneFallback
{
    public function __construct(
        private InstanceFirm $firm,
        private InvoicingCountries $countries,
    ) {
    }

    public function zone(): ?\DateTimeZone
    {
        $code = $this->firm->find()?->address()->countryCode;
        if (null === $code) {
            return null;
        }

        try {
            return $this->countries->for(trim($code))->timezone();
        } catch (UnsupportedCountry) {
            return null;
        }
    }
}

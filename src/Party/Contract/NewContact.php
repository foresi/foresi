<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

/**
 * A contact another module asks the address book to create (ContactCreation). The address book
 * keeps its own rules: a contact has a billing address, a company needs a registration number or
 * a VAT id.
 */
final readonly class NewContact
{
    /**
     * @param string $kind person, sole_trader or company
     */
    public function __construct(
        public string $kind,
        public string $displayName,
        public string $street,
        public string $city,
        public string $postalCode,
        public string $countryCode,
        public string $registrationNumber = '',
        public string $vatId = '',
        public string $email = '',
        public string $phone = '',
    ) {
    }
}

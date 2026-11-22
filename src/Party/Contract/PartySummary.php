<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

use Foresi\Party\Contract\Event\ContactKind;

/**
 * One contact as another module shows it, for example on a document it issues to the contact.
 */
final readonly class PartySummary
{
    /**
     * @param list<string> $addressLines the billing address, else the first address, line by line
     *                                   (street, postal code and city, country code); empty without one
     * @param string|null  $email        the general e-mail address
     * @param string|null  $phone        the phone, else the mobile
     * @param list<string> $roles        customer, supplier, partner, authority
     */
    public function __construct(
        public string $id,
        public ContactKind $kind,
        public string $displayName,
        public ?string $registrationNumber,
        public ?string $vatId,
        public array $addressLines,
        public ?string $email,
        public ?string $phone,
        public array $roles,
        public bool $archived,
    ) {
    }
}

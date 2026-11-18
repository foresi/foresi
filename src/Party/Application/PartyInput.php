<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\InvoicingDefaults;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;

/**
 * Everything the address book form collects about a party, already parsed into
 * domain values. The e-mail is the general one; an empty string means none.
 * Document defaults are left as they are when null (the form does not edit them).
 */
final readonly class PartyInput
{
    /**
     * @param list<PartyRole> $roles
     */
    public function __construct(
        public PartyKind $kind,
        public string $displayName,
        public ?RegistrationNumber $registrationNumber,
        public ?VatId $vatId,
        public string $email,
        public string $phone,
        public array $roles,
        public Address $billingAddress,
        public string $privateNote = '',
        public string $mobile = '',
        public string $website = '',
        public ?InvoicingDefaults $invoicingDefaults = null,
    ) {
    }
}

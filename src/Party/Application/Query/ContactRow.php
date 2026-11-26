<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application\Query;

use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\InvoicingDefaults;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;

/**
 * One contact as a list shows it.
 */
final readonly class ContactRow
{
    /**
     * @param list<PartyRole> $roles
     * @param string|null     $email   the general e-mail address; none when the contact has no address of that purpose
     * @param int             $dueDays how many days after issue an invoice to it is due
     */
    public function __construct(
        public string $id,
        public PartyKind $kind,
        public string $name,
        public array $roles,
        public ?string $registrationCountry,
        public ?string $registrationNumber,
        public ?string $vatId,
        public ?string $email,
        public ?Address $billingAddress,
        public bool $archived,
        public int $dueDays = InvoicingDefaults::DEFAULT_DUE_DAYS,
    ) {
    }

    public static function of(Party $party, ?Address $billingAddress, ?string $email): self
    {
        return new self(
            $party->id(),
            $party->kind(),
            $party->displayName(),
            $party->roles(),
            $party->registrationNumber()?->countryCode,
            $party->registrationNumber()?->value,
            $party->vatId()?->toString(),
            $email,
            $billingAddress,
            $party->isArchived(),
            $party->invoicingDefaults()->dueDays,
        );
    }
}

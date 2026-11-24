<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Reading;

use Foresi\Party\Application\DuplicateFinder;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Application\RegistrationNumberFactory;
use Foresi\Party\Domain\InvalidRegistrationNumber;
use Foresi\Party\Domain\InvalidVatId;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Purchasing\Application\ReceivedDocumentContents;
use Foresi\Purchasing\Application\ReceivedDocumentWarning;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Contract\Reading\Reading;

/**
 * Matches through the address book's own duplicate finder, archived parties
 * left out. What a reading says is data: it is only ever compared, never run.
 */
final readonly class PartySupplierMatching implements SupplierMatching
{
    public function __construct(
        private DuplicateFinder $finder,
        private RegistrationNumberFactory $numbers,
        private PartyQuery $parties,
        private ReceivedDocumentContents $contents,
    ) {
    }

    public function match(Reading $reading): SupplierMatch
    {
        $vatId = $this->vatId($reading);
        $number = $this->registrationNumber($reading, $vatId);
        if (!$number instanceof RegistrationNumber && !$vatId instanceof VatId) {
            // Named without a usable identifier: nobody to look for, but the detail offers to create the supplier.
            $named = array_any([ReadField::SupplierName, ReadField::SupplierRegistrationNumber, ReadField::SupplierVatId], static fn (ReadField $field): bool => \is_string($reading->get($field)?->value) && '' !== trim($reading->get($field)->value));

            return $named ? new SupplierMatch(null, ReceivedDocumentWarning::SUPPLIER_NOT_FOUND) : SupplierMatch::none();
        }
        // The registration number decides when it finds anybody; the VAT id only when it does not.
        $found = $number instanceof RegistrationNumber ? $this->finder->find($number, null, false) : [];
        if ([] === $found && $vatId instanceof VatId) {
            $found = $this->finder->find(null, $vatId, false);
        }
        if ([] === $found) {
            return new SupplierMatch(null, ReceivedDocumentWarning::SUPPLIER_NOT_FOUND);
        }
        if (\count($found) > 1) {
            return new SupplierMatch(null, ReceivedDocumentWarning::SUPPLIER_AMBIGUOUS);
        }
        $party = $this->parties->find($found[0]->partyId);
        if (!$party instanceof Party || $party->isArchived()) {
            return new SupplierMatch(null, ReceivedDocumentWarning::SUPPLIER_NOT_FOUND);
        }

        return new SupplierMatch($party, null, $this->contents->supplierSnapshot($party));
    }

    private function vatId(Reading $reading): ?VatId
    {
        $value = $reading->get(ReadField::SupplierVatId)?->value;
        if (!\is_string($value)) {
            return null;
        }
        try {
            return VatId::fromString($value);
        } catch (InvalidVatId) {
            return null;
        }
    }

    /**
     * The country is the one the reading names, else that of the VAT id.
     */
    private function registrationNumber(Reading $reading, ?VatId $vatId): ?RegistrationNumber
    {
        $value = $reading->get(ReadField::SupplierRegistrationNumber)?->value;
        $country = $reading->get(ReadField::SupplierCountry)?->value;
        $country = \is_string($country) && 1 === preg_match('/^[A-Z]{2}$/D', strtoupper(trim($country))) ? strtoupper(trim($country)) : $vatId?->country();
        if (!\is_string($value) || !\is_string($country)) {
            return null;
        }
        try {
            return $this->numbers->create($country, $value);
        } catch (InvalidRegistrationNumber) {
        }
        // A number whose check fails may still be the one an address book holds.
        try {
            return new RegistrationNumber($country, trim($value));
        } catch (InvalidRegistrationNumber) {
            return null;
        }
    }
}

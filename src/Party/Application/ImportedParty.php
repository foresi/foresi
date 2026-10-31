<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\Address;
use App\Party\Domain\Iban;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;

/**
 * One imported row read as a party: the domain values, what is wrong with
 * them, and which fields the row carries at all (a field the source has no
 * column for is never touched; an empty cell may be).
 */
final readonly class ImportedParty
{
    /**
     * @param array<string, mixed>                                    $values       the normalised values by field key, null for an empty cell
     * @param array<string, array{string, array<string, int|string>}> $errors       field key => translation key and its parameters
     * @param list<string>                                            $emails       the general e-mail first, then the copies
     * @param list<PartyRole>                                         $roles        empty when the row names none
     * @param bool                                                    $billingGiven true when the row has a street, city or postal code
     */
    public function __construct(
        public array $values,
        public array $errors,
        public PartyKind $kind,
        public string $displayName,
        public ?RegistrationNumber $registrationNumber,
        public ?VatId $vatId,
        public array $emails,
        public ?string $invoiceEmail,
        public Address $billingAddress,
        public bool $billingGiven,
        public ?Address $deliveryAddress,
        public ?Iban $iban,
        public ?string $bic,
        public ?ImportedContact $contact,
        public array $roles,
    ) {
    }

    /** True when the row has a non-empty value for the field. */
    public function given(string $field): bool
    {
        return null !== ($this->values[$field] ?? null);
    }

    /** True when the row has a column for the field and the cell is empty. */
    public function blank(string $field): bool
    {
        return \array_key_exists($field, $this->values) && null === $this->values[$field];
    }

    /** The text of a field, or an empty string. */
    public function text(string $field): string
    {
        $value = $this->values[$field] ?? null;

        return \is_string($value) ? $value : '';
    }
}

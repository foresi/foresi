<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;

/**
 * Everything the address book form collects about a party, already parsed into
 * domain values. The e-mail is the general one; an empty string means none.
 * Document defaults are left as they are when null (the form does not edit them).
 * The custom fields (ADR 0045) are the parsed values by key of the fields the form
 * shows; null when the caller offers none, so they stay as they are and a new
 * contact is not asked for the required ones.
 */
final readonly class PartyInput
{
    /**
     * @param list<PartyRole>           $roles
     * @param array<string, mixed>|null $customFields by field key, as Fields::write() takes them
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
        public ?array $customFields = null,
    ) {
    }

    /**
     * The same input with the values of the custom fields the form shows.
     *
     * @param array<string, mixed> $customFields by field key
     */
    public function withCustomFields(array $customFields): self
    {
        return new self($this->kind, $this->displayName, $this->registrationNumber, $this->vatId, $this->email, $this->phone, $this->roles, $this->billingAddress, $this->privateNote, $this->mobile, $this->website, $customFields);
    }
}

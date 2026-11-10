<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Domain;

use App\Party\Domain\Address;
use App\Party\Domain\Party;
use Doctrine\ORM\Mapping as ORM;

/**
 * The supplier as the document names it, frozen on the document: editing the
 * contact later changes nothing here. A draft without a supplier holds an empty
 * name.
 */
#[ORM\Embeddable]
final readonly class SupplierSnapshot
{
    public const int MAX_NAME = 255;
    public const int MAX_REGISTRATION_NUMBER = 64;
    public const int MAX_VAT_ID = 14;

    public function __construct(
        #[ORM\Column(type: 'string', length: self::MAX_NAME)]
        public string $name,
        #[ORM\Column(name: 'registration_number', type: 'string', length: self::MAX_REGISTRATION_NUMBER, nullable: true)]
        public ?string $registrationNumber,
        #[ORM\Column(name: 'vat_id', type: 'string', length: self::MAX_VAT_ID, nullable: true)]
        public ?string $vatId,
        #[ORM\Embedded(class: Address::class, columnPrefix: 'address_')]
        public Address $address,
    ) {
    }

    public static function none(): self
    {
        return new self('', null, null, new Address('', '', '', ''));
    }

    public static function of(Party $party, Address $address): self
    {
        return new self(mb_substr($party->displayName(), 0, self::MAX_NAME), $party->registrationNumber()?->toString(), $party->vatId()?->toString(), $address);
    }
}

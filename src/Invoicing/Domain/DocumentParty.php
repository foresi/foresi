<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use Doctrine\ORM\Mapping as ORM;

/**
 * A frozen copy of a customer or an issuer on a document. It holds plain values:
 * editing the contact or the firm later changes nothing here. The logo and the
 * stamp name stored images, which are never deleted, see FirmImages.
 */
#[ORM\Embeddable]
final readonly class DocumentParty
{
    public const int MAX_NAME = 255;
    public const int MAX_REGISTRATION_NUMBER = 64;
    public const int MAX_VAT_ID = 14;
    public const int MAX_REGISTER_ENTRY = 255;
    public const int MAX_EMAIL = 254;
    public const int MAX_PHONE = 64;
    public const int MAX_WEB = 255;
    public const int MAX_STREET = 255;
    public const int MAX_CITY = 128;
    public const int MAX_POSTAL_CODE = 32;

    public function __construct(
        #[ORM\Column(type: 'string', length: self::MAX_NAME)]
        public string $name,
        #[ORM\Column(name: 'registration_number', type: 'string', length: self::MAX_REGISTRATION_NUMBER, nullable: true)]
        public ?string $registrationNumber,
        #[ORM\Column(name: 'vat_id', type: 'string', length: self::MAX_VAT_ID, nullable: true)]
        public ?string $vatId,
        #[ORM\Embedded(class: Address::class, columnPrefix: 'address_')]
        public Address $address,
        #[ORM\Column(name: 'register_entry', type: 'string', length: self::MAX_REGISTER_ENTRY)]
        public string $registerEntry,
        #[ORM\Column(type: 'string', length: self::MAX_EMAIL, nullable: true)]
        public ?string $email,
        #[ORM\Column(type: 'string', length: self::MAX_PHONE)]
        public string $phone,
        #[ORM\Column(type: 'string', length: self::MAX_WEB)]
        public string $web,
        #[ORM\Column(type: 'string', length: 72, nullable: true)]
        public ?string $logo,
        #[ORM\Column(type: 'string', length: 72, nullable: true)]
        public ?string $stamp,
    ) {
    }

    public static function ofCustomer(Party $party, Address $billing, ?string $email): self
    {
        return new self($party->displayName(), $party->registrationNumber()?->toString(), $party->vatId()?->toString(), $billing, '', $email, $party->phone(), $party->website(), null, null);
    }

    public static function ofIssuer(LegalEntity $firm, FirmInvoicing $settings): self
    {
        $email = $settings->contactEmail();

        return new self(
            $firm->name(),
            $firm->registrationNumber()->toString(),
            $firm->vatId()?->toString(),
            $firm->address(),
            $firm->registerEntry(),
            '' === $email ? null : $email,
            $settings->contactPhone(),
            $settings->contactWeb(),
            $firm->logo(),
            $firm->stamp(),
        );
    }
}

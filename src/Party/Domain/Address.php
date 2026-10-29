<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final readonly class Address
{
    public function __construct(
        #[ORM\Column(type: 'string', length: 255)]
        public string $street,
        #[ORM\Column(type: 'string', length: 128)]
        public string $city,
        #[ORM\Column(name: 'postal_code', type: 'string', length: 32)]
        public string $postalCode,
        #[ORM\Column(name: 'country_code', type: 'string', length: 2, options: ['fixed' => true])]
        public string $countryCode,
    ) {
    }
}

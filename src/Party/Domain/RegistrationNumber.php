<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A company registration number of a given country. Create it through
 * RegistrationNumberFactory, which applies the country's validation rules.
 * The constructor only guards structural invariants; values hydrated from the
 * database are trusted and not re-validated.
 */
#[ORM\Embeddable]
final readonly class RegistrationNumber
{
    public function __construct(
        #[ORM\Column(name: 'registration_country', type: 'string', length: 2, options: ['fixed' => true])]
        public string $countryCode,
        #[ORM\Column(name: 'registration_number', type: 'string', length: 64)]
        public string $value,
    ) {
        if (1 !== preg_match('/^[A-Z]{2}$/', $countryCode)) {
            throw new InvalidRegistrationNumber('The country must be an ISO 3166-1 alpha-2 code.');
        }
        if ('' === trim($value)) {
            throw new InvalidRegistrationNumber('A registration number must not be empty.');
        }
    }

    public function toString(): string
    {
        return $this->value;
    }
}

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
        if ('' === $this->normalised()) {
            throw new InvalidRegistrationNumber('A registration number needs at least one letter or digit.');
        }
    }

    public function toString(): string
    {
        return $this->value;
    }

    /**
     * The comparison form: letters and digits only (any script), upper case. "1234 5679"
     * and "12345679" are the same number.
     */
    public function normalised(): string
    {
        return mb_strtoupper((string) preg_replace('/[^\p{L}\p{N}]/u', '', $this->value));
    }
}

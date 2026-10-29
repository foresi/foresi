<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\InvalidRegistrationNumber;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\RegistrationNumberValidator;

/**
 * Builds registration numbers using the validator registered for the country.
 * Countries without a validator accept any trimmed, non-empty value.
 */
final readonly class RegistrationNumberFactory
{
    /**
     * @param iterable<RegistrationNumberValidator> $validators
     */
    public function __construct(private iterable $validators)
    {
    }

    /**
     * @throws InvalidRegistrationNumber
     */
    public function create(string $countryCode, string $raw): RegistrationNumber
    {
        $country = strtoupper(trim($countryCode));
        if (1 !== preg_match('/^[A-Z]{2}$/', $country)) {
            throw new InvalidRegistrationNumber('The country must be an ISO 3166-1 alpha-2 code.');
        }

        foreach ($this->validators as $validator) {
            if ($validator->supports($country)) {
                $value = $validator->normalize($raw);
                $validator->validate($value);

                return new RegistrationNumber($country, $value);
            }
        }

        $value = trim($raw);
        if ('' === $value) {
            throw new InvalidRegistrationNumber('A registration number must not be empty.');
        }

        return new RegistrationNumber($country, $value);
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Country\CzechRepublic\Party;

use App\Party\Domain\InvalidRegistrationNumber;
use App\Party\Domain\RegistrationNumberValidator;

/**
 * Czech IČO: eight digits, the last one a modulo 11 check digit.
 */
final class CzechRegistrationNumberValidator implements RegistrationNumberValidator
{
    private const array WEIGHTS = [8, 7, 6, 5, 4, 3, 2];

    public function supports(string $countryCode): bool
    {
        return 'CZ' === $countryCode;
    }

    public function normalize(string $raw): string
    {
        $digits = (string) preg_replace('/\s+/', '', $raw);

        return ctype_digit($digits) ? str_pad($digits, 8, '0', STR_PAD_LEFT) : $digits;
    }

    public function validate(string $normalized): void
    {
        if (1 !== preg_match('/^[0-9]{8}\z/', $normalized)) {
            throw new InvalidRegistrationNumber('A Czech registration number has exactly 8 digits.');
        }

        $sum = 0;
        foreach (self::WEIGHTS as $index => $weight) {
            $sum += (int) $normalized[$index] * $weight;
        }
        $check = (11 - $sum % 11) % 10;

        if ($check !== (int) $normalized[7]) {
            throw new InvalidRegistrationNumber('The Czech registration number has an invalid check digit.');
        }
    }
}

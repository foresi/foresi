<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Support\Registry;

use App\Party\Application\CompanyRegistry;
use App\Party\Application\RegistryRecord;
use App\Party\Domain\Address;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;

/**
 * Test-env stand-in for the company register: knows one synthetic subject,
 * registration number 12345687 ("Example Company s.r.o."), and nothing else.
 */
final class FakeCompanyRegistry implements CompanyRegistry
{
    public const string KNOWN_NUMBER = '12345687';

    public function lookupByRegistrationNumber(RegistrationNumber $number): ?RegistryRecord
    {
        return 'CZ' === $number->countryCode && self::KNOWN_NUMBER === $number->normalised() ? self::exampleCompany() : null;
    }

    public function searchByName(string $country, string $query, int $limit): array
    {
        $needle = mb_strtolower(trim($query));
        if ('CZ' !== strtoupper($country) || $limit < 1 || '' === $needle || !str_contains('example company s.r.o.', $needle)) {
            return [];
        }

        return [self::exampleCompany()];
    }

    public static function exampleCompany(): RegistryRecord
    {
        return new RegistryRecord(
            new RegistrationNumber('CZ', self::KNOWN_NUMBER),
            'Example Company s.r.o.',
            VatId::fromString('CZ12345687'),
            new Address('Příkladná 1444/2a', 'Ostrava', '702 00', 'CZ'),
        );
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure;

use App\Country\CzechRepublic\Party\CzechRegistrationNumberValidator;
use App\Party\Application\CompanyRegistry;
use App\Party\Application\RegistrationNumberFactory;
use App\Party\Application\RegistryRecord;
use App\Party\Domain\RegistrationNumber;
use App\Party\Infrastructure\Registry\RegistryLookup;
use PHPUnit\Framework\TestCase;

final class RegistryLookupTest extends TestCase
{
    public function testNameSearchStartsAtThreeCharactersAndIsCutToOneHundred(): void
    {
        $registry = new class implements CompanyRegistry {
            /** @var list<string> */
            public array $searches = [];
            public int $lookups = 0;

            public function lookupByRegistrationNumber(RegistrationNumber $number): ?RegistryRecord
            {
                ++$this->lookups;

                return null;
            }

            public function searchByName(string $country, string $query, int $limit): array
            {
                $this->searches[] = $query;

                return [];
            }
        };
        $lookup = new RegistryLookup($registry, new RegistrationNumberFactory([new CzechRegistrationNumberValidator()]));

        $lookup->find('ab');
        $lookup->find('abc');
        $lookup->find(str_repeat('é', 150));
        $lookup->find('12345');
        $lookup->find('12345687');

        self::assertSame(['abc', str_repeat('é', 100)], $registry->searches);
        self::assertSame(1, $registry->lookups);
    }
}

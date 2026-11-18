<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Registry;

use Foresi\Party\Application\CompanyRegistry;
use Foresi\Party\Application\RegistryRecord;
use Foresi\Party\Domain\RegistrationNumber;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every company register an installed country module offers, such as the
 * Czech one. Each answers for its own country only; with none installed,
 * lookups come back empty and contacts are entered by hand.
 */
final readonly class CollectedCompanyRegistry implements CompanyRegistry
{
    /**
     * @param iterable<CompanyRegistry> $registries
     */
    public function __construct(
        #[AutowireIterator(CompanyRegistry::TAG)]
        private iterable $registries,
    ) {
    }

    public function lookupByRegistrationNumber(RegistrationNumber $number): ?RegistryRecord
    {
        foreach ($this->registries as $registry) {
            $record = $registry->lookupByRegistrationNumber($number);
            if (null !== $record) {
                return $record;
            }
        }

        return null;
    }

    public function searchByName(string $country, string $query, int $limit): array
    {
        $records = [];
        foreach ($this->registries as $registry) {
            if (count($records) >= $limit) {
                break;
            }
            array_push($records, ...$registry->searchByName($country, $query, $limit - count($records)));
        }

        return $records;
    }
}

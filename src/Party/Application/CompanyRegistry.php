<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\RegistrationNumber;

/**
 * A public company register. Lookups never throw: an unknown subject and an
 * unreachable register both come back empty (the adapter logs the latter).
 */
interface CompanyRegistry
{
    public const string TAG = 'foresi.party.company_registry';

    public function lookupByRegistrationNumber(RegistrationNumber $number): ?RegistryRecord;

    /**
     * @return list<RegistryRecord>
     */
    public function searchByName(string $country, string $query, int $limit): array;
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Registry;

use App\Party\Application\CompanyRegistry;
use App\Party\Application\RegistrationNumberFactory;
use App\Party\Application\RegistryRecord;
use App\Party\Domain\InvalidRegistrationNumber;

/**
 * Turns what a user typed into registry records: a Czech registration number
 * is looked up directly, three or more other characters search by name.
 */
final readonly class RegistryLookup
{
    public const int MIN_NAME_LENGTH = 3;
    public const int LIMIT = 8;

    public function __construct(private CompanyRegistry $registry, private RegistrationNumberFactory $registrationNumbers)
    {
    }

    /**
     * @return list<RegistryRecord>
     */
    public function find(string $query): array
    {
        $query = trim($query);
        $digits = (string) preg_replace('/\s+/', '', $query);
        if ('' !== $digits && ctype_digit($digits) && \strlen($digits) <= 8) {
            try {
                $record = $this->registry->lookupByRegistrationNumber($this->registrationNumbers->create('CZ', $digits));
            } catch (InvalidRegistrationNumber) {
                return [];
            }

            return $record instanceof RegistryRecord ? [$record] : [];
        }

        if (mb_strlen($query) < self::MIN_NAME_LENGTH) {
            return [];
        }

        return $this->registry->searchByName('CZ', $query, self::LIMIT);
    }
}

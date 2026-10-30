<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

/**
 * The stored outcome of one registry lookup (VIES, unreliable-payer register)
 * and when it was made. The lookups themselves live elsewhere.
 */
final readonly class RegistryCheck
{
    public function __construct(
        public RegistrySource $source,
        public RegistryResult $result,
        public \DateTimeImmutable $checkedAt,
    ) {
        if (!$result->isPossibleFor($source)) {
            throw new \DomainException(sprintf('The result "%s" is not a result of the %s check.', $result->value, $source->value));
        }
    }
}

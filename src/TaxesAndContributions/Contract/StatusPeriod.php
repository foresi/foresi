<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Foresi\Shared\Domain\Money;

/**
 * A status of the owner from a day on, until the next period of its kind. The
 * base is the monthly base of voluntary sickness insurance when it is on.
 */
final readonly class StatusPeriod
{
    public function __construct(
        public string $id,
        public StatusKind $kind,
        public string $value,
        public \DateTimeImmutable $validFrom,
        public ?Money $base = null,
    ) {
    }
}

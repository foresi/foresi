<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Shared\Domain\Money;

/**
 * One VAT period as the matching sees it: its key and last day, what it
 * expects (positive to pay, negative to get back), the due date of the
 * period after it, which closes its window, null when no period follows,
 * and whether a person marked it settled.
 */
final readonly class SettlementPeriod
{
    public function __construct(
        public string $key,
        public \DateTimeImmutable $end,
        public Money $expected,
        public ?\DateTimeImmutable $nextDueOn,
        public bool $marked = false,
    ) {
    }
}

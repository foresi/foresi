<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * What a regime computes a year from: the owner profile, the amounts and the
 * days. The cut-off is today for "to date" and 31 December for "year end";
 * only the months that start on it or before count.
 */
final readonly class RegimeInput
{
    public function __construct(
        public int $year,
        public ProfileView $profile,
        public AmountsView $amounts,
        public \DateTimeImmutable $today,
        public \DateTimeImmutable $cutOff,
    ) {
    }
}

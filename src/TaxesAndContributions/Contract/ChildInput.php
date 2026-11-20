<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * A child the owner claims in a year: a label of the owner's choice, the birth
 * date, its order among the claimed children (1, 2, 3 and more), whether it
 * holds a severe disability card (ZTP/P) and the months it is claimed, 0 to 12.
 */
final readonly class ChildInput
{
    public function __construct(
        public string $label,
        public \DateTimeImmutable $birthDate,
        public int $order,
        public bool $ztpP,
        public int $months,
    ) {
    }
}

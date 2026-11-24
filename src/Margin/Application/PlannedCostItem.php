<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

/**
 * A planned item as its mapping needs it: whether money goes out for it,
 * whether it recurs and who it is with.
 */
final readonly class PlannedCostItem
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $expense,
        public bool $recurring,
        public ?string $counterpartyId,
    ) {
    }
}

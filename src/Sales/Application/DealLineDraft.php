<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

/**
 * A line of a deal as it was sent, before DealLineRules checked it.
 */
final readonly class DealLineDraft
{
    public function __construct(
        public string $name,
        public string $unit,
        public ?string $quantity,
        public ?string $unitPrice,
        public string $currency,
        public string $billingKind,
        public ?string $recurrenceUnit,
        public ?int $interval,
        public ?int $cycles,
    ) {
    }
}

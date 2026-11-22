<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Application;

/**
 * A product as it was sent, before ProductRules checked it.
 */
final readonly class ProductDraft
{
    public function __construct(
        public string $name,
        public string $description,
        public string $unit,
        public ?string $unitPrice,
        public string $currency,
        public string $billingKind,
        public ?string $recurrenceUnit,
        public ?int $interval,
        public ?int $cycles,
    ) {
    }
}

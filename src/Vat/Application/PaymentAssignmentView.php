<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

/**
 * What a person decided about a bank movement: the period it pays, null for
 * no VAT payment, or nothing any more, so the automatic matching decides.
 */
final readonly class PaymentAssignmentView
{
    public function __construct(
        public string $movementId,
        public ?string $period,
        public bool $automatic,
    ) {
    }
}

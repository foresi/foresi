<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application\Query;

use App\Invoicing\Domain\PaymentDirection;
use App\Invoicing\Domain\PaymentSource;
use App\Party\Domain\PaymentMethod;

/**
 * What a slice of the payments is narrowed to. Every field narrows; the payments
 * that match all of them are listed.
 */
final readonly class PaymentSliceFilter
{
    /**
     * @param string|null             $firmId           only the payments of this firm
     * @param string|null             $invoiceId        only the payments that pay, or refund, this invoice
     * @param \DateTimeImmutable|null $receivedFrom     the first day, inclusive
     * @param \DateTimeImmutable|null $receivedTo       the last day, inclusive
     * @param bool                    $includeCancelled whether the cancelled payments are listed too
     */
    public function __construct(
        public ?string $firmId = null,
        public ?string $invoiceId = null,
        public ?PaymentDirection $direction = null,
        public ?\DateTimeImmutable $receivedFrom = null,
        public ?\DateTimeImmutable $receivedTo = null,
        public bool $includeCancelled = false,
        public ?PaymentSource $source = null,
        public ?PaymentMethod $method = null,
    ) {
    }
}

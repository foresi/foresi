<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\Query;

use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Domain\PaymentDirection;
use Foresi\Invoicing\Domain\PaymentSource;

/**
 * What a slice of the payments is narrowed to. Every field narrows; the payments
 * that match all of them are listed.
 */
final readonly class PaymentSliceFilter
{
    /**
     * @param string|null             $invoiceId        only the payments that pay, or refund, this invoice
     * @param \DateTimeImmutable|null $receivedFrom     the first day, inclusive
     * @param \DateTimeImmutable|null $receivedTo       the last day, inclusive
     * @param bool                    $includeCancelled whether the cancelled payments are listed too
     */
    public function __construct(
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

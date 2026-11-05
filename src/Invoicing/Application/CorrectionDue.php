<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Shared\Domain\Money;

/**
 * What a delivered tax document on payment still has to be corrected by, with VAT,
 * why, and the day of the fact: the cancellation of its payment or the last refund
 * on its advance.
 */
final readonly class CorrectionDue
{
    public function __construct(
        public Money $amount,
        public bool $refunded,
        public \DateTimeImmutable $decisiveDate,
    ) {
    }

    /**
     * A change after the supply is corrected within 15 days of the fact (§42).
     */
    public function deadline(): \DateTimeImmutable
    {
        return $this->decisiveDate->modify('+15 days');
    }
}

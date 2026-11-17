<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Purchasing\Domain\ReceivedDocument;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * What a received document asks, what its payments add up to and what remains,
 * derived and never stored (ADR 0019). The amounts are positive; a credit note
 * remains until the supplier has returned its total. A draft and a document
 * without a total have nothing to pay.
 */
final readonly class PaymentBalance
{
    public function __construct(
        public Currency $currency,
        public Money $due,
        public Money $paid,
        public Money $remaining,
        public ?\DateTimeImmutable $lastPaidOn,
    ) {
    }

    public static function of(ReceivedDocument $document, int $paidMinor, ?\DateTimeImmutable $lastPaidOn): self
    {
        $currency = $document->currency();
        $total = $document->isRecorded() ? $document->total() : null;
        $dueMinor = $total instanceof Money ? abs($total->minor()) : 0;

        return new self(
            $currency,
            Money::ofMinor($dueMinor, $currency),
            Money::ofMinor($paidMinor, $currency),
            Money::ofMinor(max(0, $dueMinor - $paidMinor), $currency),
            $lastPaidOn,
        );
    }

    /**
     * Recorded, with something to pay, and nothing of it left.
     */
    public function isPaid(): bool
    {
        return !$this->due->isZero() && $this->remaining->isZero();
    }
}

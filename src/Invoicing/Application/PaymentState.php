<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Shared\Domain\CurrencyMismatch;
use App\Shared\Domain\Money;

/**
 * How far a document is paid, derived from what its allocations add up to and never
 * stored. A document with nothing to pay, zero or less, is always Unpaid: it has no
 * payment state to show.
 */
enum PaymentState: string
{
    case Unpaid = 'unpaid';
    case PartlyPaid = 'partly_paid';
    case Paid = 'paid';
    case Overpaid = 'overpaid';

    /**
     * @param Money $paid the allocated amounts and settlements of the payments not cancelled
     *
     * @throws CurrencyMismatch
     */
    public static function of(Money $payable, Money $paid): self
    {
        if ($payable->currency() !== $paid->currency()) {
            throw new CurrencyMismatch('The paid amount is in another currency than the document.');
        }
        $due = $payable->minor();
        $received = $paid->minor();

        return match (true) {
            $due <= 0, $received <= 0 => self::Unpaid,
            $received < $due => self::PartlyPaid,
            $received === $due => self::Paid,
            default => self::Overpaid,
        };
    }

    /**
     * Whether nothing more is due: what the paid tab shows, and what is never overdue.
     */
    public function isSettled(): bool
    {
        return self::Paid === $this || self::Overpaid === $this;
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentKind;
use App\Shared\Domain\CurrencyMismatch;
use App\Shared\Domain\Money;

/**
 * How far a document is paid, derived from what its allocations add up to and never
 * stored. A document with nothing to pay, zero or less, is always Unpaid: it has no
 * payment state to show. The corrections of an invoice change what is due: a credit
 * note lowers it and a debit note raises it. Credited down to nothing and unpaid, it
 * is Paid; paid above what is now due, it is ToRefund.
 */
enum PaymentState: string
{
    case Unpaid = 'unpaid';
    case PartlyPaid = 'partly_paid';
    case Paid = 'paid';
    case Overpaid = 'overpaid';
    /** Its corrections brought what is due below what was paid: the difference goes back. */
    case ToRefund = 'to_refund';

    /**
     * @param Money      $paid        the allocated amounts and settlements of the payments not cancelled
     * @param Money|null $corrections the payable of its corrective documents not in the trash, added up
     *
     * @throws CurrencyMismatch
     */
    public static function of(Money $payable, Money $paid, ?Money $corrections = null): self
    {
        if ($payable->currency() !== $paid->currency() || ($corrections instanceof Money && $corrections->currency() !== $payable->currency())) {
            throw new CurrencyMismatch('The paid amount is in another currency than the document.');
        }
        $due = $payable->minor();
        $received = $paid->minor();
        if ($corrections instanceof Money && !$corrections->isZero() && $due > 0) {
            $due += $corrections->minor();

            return match (true) {
                $received > max(0, $due) => self::ToRefund,
                $due <= 0, $received === $due => self::Paid,
                $received <= 0 => self::Unpaid,
                default => self::PartlyPaid,
            };
        }

        return match (true) {
            $due <= 0, $received <= 0 => self::Unpaid,
            $received < $due => self::PartlyPaid,
            $received === $due => self::Paid,
            default => self::Overpaid,
        };
    }

    /**
     * The state of a document of a kind: a tax document on payment is paid by the
     * payment it taxes, so it is paid whatever is allocated to it. A corrective
     * document has no payment state of its own: its money follows its invoice.
     *
     * @throws CurrencyMismatch
     */
    public static function ofDocument(DocumentKind $kind, Money $payable, Money $paid, ?Money $corrections = null): self
    {
        if (DocumentKind::CorrectionDocument === $kind) {
            return self::Unpaid;
        }

        return self::of($payable, $kind->isPayable() ? $paid : $payable, $corrections);
    }

    /**
     * Whether nothing more is due: what the paid tab shows, and what is never overdue.
     */
    public function isSettled(): bool
    {
        return self::Paid === $this || self::Overpaid === $this || self::ToRefund === $this;
    }
}

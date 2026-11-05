<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Shared\Domain\Money;

/**
 * The one status a document shows, always derived and never stored: the most relevant
 * of what is true of it. The trash outranks everything; a document paid in full or
 * more needs nothing more, so it is never overdue; what is overdue outranks a part
 * payment and a delivery, because that is what someone has to act on. Payment states
 * do not get a column of their own: they come from the allocations.
 */
enum InvoiceStatus: string
{
    case Issued = 'issued';
    case Delivered = 'delivered';
    case PartlyPaid = 'partly_paid';
    case Overdue = 'overdue';
    case Paid = 'paid';
    case Overpaid = 'overpaid';
    case Trashed = 'trashed';
    /** Its deducted advances cover what it is for: nothing is left to pay, or there is a difference to refund. */
    case SettledByAdvances = 'settled_by_advances';

    /**
     * @param bool $overdue past its due date; a settled payment state outranks it
     */
    public static function of(bool $delivered, bool $overdue, bool $trashed, PaymentState $payment): self
    {
        return match (true) {
            $trashed => self::Trashed,
            PaymentState::Overpaid === $payment => self::Overpaid,
            PaymentState::Paid === $payment => self::Paid,
            $overdue => self::Overdue,
            PaymentState::PartlyPaid === $payment => self::PartlyPaid,
            $delivered => self::Delivered,
            default => self::Issued,
        };
    }

    /**
     * @param \DateTimeImmutable $today in the time zone of the country that issued the document
     * @param Money              $paid  as InvoicePayments::paidOf() gives it
     */
    public static function ofInvoice(Invoice $invoice, \DateTimeImmutable $today, Money $paid): self
    {
        if (!$invoice->isTrashed() && $invoice->deductsAdvances() && $invoice->payable()->minor() <= 0) {
            return self::SettledByAdvances;
        }

        return self::of(
            $invoice->isDelivered(),
            $invoice->dueDate()->format('Y-m-d') < $today->format('Y-m-d'),
            $invoice->isTrashed(),
            PaymentState::ofDocument($invoice->kind(), $invoice->payable(), $paid),
        );
    }

    public function tone(): string
    {
        return match ($this) {
            self::Issued => 'gray',
            self::Delivered, self::Paid, self::SettledByAdvances => 'green',
            self::Overdue => 'red',
            self::Trashed, self::PartlyPaid, self::Overpaid => 'amber',
        };
    }
}

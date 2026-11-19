<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Shared\Domain\Money;

/**
 * The one status a document shows, always derived and never stored: the most relevant
 * of what is true of it, along its life of issued, sent and paid. The first that applies of:
 *
 *  1. in the trash;
 *  2. to refund, overpaid, paid (a document paid in full or more needs nothing more, so it is
 *     never overdue, and paid wins over sent);
 *  3. overdue (unpaid or partly paid past its due date, sent or not: that is what someone acts on);
 *  4. partly paid;
 *  5. sent (it went to the customer, by e-mail or marked by hand);
 *  6. issued.
 *
 * Sent, not delivered: that a message reached the customer is never certain, only that it left.
 * Payment states do not get a column of their own: they come from the allocations.
 */
enum InvoiceStatus: string
{
    case Issued = 'issued';
    case Sent = 'sent';
    case PartlyPaid = 'partly_paid';
    case Overdue = 'overdue';
    case Paid = 'paid';
    case Overpaid = 'overpaid';
    /** Its corrections brought what is due below what was paid. */
    case ToRefund = 'to_refund';
    case Trashed = 'trashed';
    /** Its deducted advances cover what it is for: nothing is left to pay, or there is a difference to refund. */
    case SettledByAdvances = 'settled_by_advances';

    /**
     * @param bool $overdue past its due date; a settled payment state outranks it
     */
    public static function of(bool $sent, bool $overdue, bool $trashed, PaymentState $payment): self
    {
        return match (true) {
            $trashed => self::Trashed,
            PaymentState::ToRefund === $payment => self::ToRefund,
            PaymentState::Overpaid === $payment => self::Overpaid,
            PaymentState::Paid === $payment => self::Paid,
            $overdue => self::Overdue,
            PaymentState::PartlyPaid === $payment => self::PartlyPaid,
            $sent => self::Sent,
            default => self::Issued,
        };
    }

    /**
     * A corrective document is never owing, paid or overdue: its money follows its invoice.
     */
    public static function ofCorrection(bool $trashed): self
    {
        return $trashed ? self::Trashed : self::Issued;
    }

    /**
     * @param \DateTimeImmutable $today       in the time zone of the country that issued the document
     * @param Money              $paid        as InvoicePayments::paidOf() gives it
     * @param Money|null         $corrections as InvoicePayments::correctionsOf() gives it
     */
    public static function ofInvoice(Invoice $invoice, \DateTimeImmutable $today, Money $paid, ?Money $corrections = null): self
    {
        if (DocumentKind::CorrectionDocument === $invoice->kind()) {
            return self::ofCorrection($invoice->isTrashed());
        }
        if (!$invoice->isTrashed() && $invoice->deductsAdvances() && $invoice->payable()->minor() <= 0) {
            return self::SettledByAdvances;
        }

        return self::of(
            $invoice->isDelivered(),
            $invoice->dueDate()->format('Y-m-d') < $today->format('Y-m-d'),
            $invoice->isTrashed(),
            PaymentState::ofDocument($invoice->kind(), $invoice->payable(), $paid, $corrections),
        );
    }

    /**
     * The translation key of its label, which agrees with the kind in Czech: an invoice and an
     * advance invoice are feminine ("Odeslaná"), a tax document and a correction document
     * masculine ("Odeslaný").
     */
    public function labelKey(DocumentKind $kind): string
    {
        return self::labelPrefix($kind).$this->value;
    }

    /**
     * The prefix of the label keys of a kind's statuses, for a list's options.
     */
    public static function labelPrefix(DocumentKind $kind): string
    {
        return match ($kind) {
            DocumentKind::Invoice, DocumentKind::Advance => 'invoice.list.status.',
            DocumentKind::PaymentTaxDocument, DocumentKind::CorrectionDocument => 'invoice.list.document_status.',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            // Green is for the money that came in, as Stripe and QuickBooks show only paid in green.
            self::Issued, self::Sent => 'gray',
            self::Paid, self::SettledByAdvances => 'green',
            self::Overdue => 'red',
            self::Trashed, self::PartlyPaid, self::Overpaid, self::ToRefund => 'amber',
        };
    }
}

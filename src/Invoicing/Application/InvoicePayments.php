<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentAllocation;
use App\Shared\Domain\Money;

/**
 * The payments of the active tenant and what they pay. The paid amount of an
 * invoice is the sum of the amounts and settlements of its allocations whose
 * payment is not cancelled; it is read, never stored. Call the writing methods
 * inside TransactionalWork, which flushes.
 */
interface InvoicePayments
{
    public function paidOf(Invoice $invoice): Money;

    /**
     * One query for all of them.
     *
     * @param list<string> $invoiceIds
     *
     * @return array<string, int> paid minor units by invoice id; an invoice with nothing paid is left out
     */
    public function paidFor(array $invoiceIds): array;

    /**
     * What the corrective documents of the invoice not in the trash add up to: below
     * zero when credit notes outweigh debit notes.
     */
    public function correctionsOf(Invoice $invoice): Money;

    /**
     * One query for all of them.
     *
     * @param list<string> $invoiceIds
     *
     * @return array<string, int> minor units by invoice id; an invoice without corrections is left out
     */
    public function correctionsFor(array $invoiceIds): array;

    /**
     * What is still to pay, its corrections included: below zero when it is
     * overpaid or there is something to refund.
     */
    public function remainingOf(Invoice $invoice): Money;

    /**
     * What may go back to the customer as a refund: what was paid above what is due
     * on an invoice whose corrections brought it below what was paid (to refund), or
     * on a final invoice whose deducted advances exceed it (difference to refund).
     * Zero for anything else, a plain overpayment included.
     */
    public function toRefundOf(Invoice $invoice): Money;

    /**
     * @return list<InvoicePaymentLine> the payments not cancelled, oldest first
     */
    public function linesOf(Invoice $invoice): array;

    /**
     * @throws UnknownPayment also for a payment of another tenant
     */
    public function get(string $paymentId): Payment;

    /**
     * Whether the invoice has an allocation of a payment not cancelled.
     */
    public function hasPayments(Invoice $invoice): bool;

    /**
     * @return list<string> the ids of the invoices the payment pays, ascending
     */
    public function invoiceIdsOf(string $paymentId): array;

    /**
     * The numbers of the invoices the payment pays, as a person tells them apart;
     * none once it is cancelled.
     *
     * @return array<string, string> number by invoice id, in the order of the numbers
     */
    public function invoiceNumbersOf(string $paymentId): array;

    public function add(Payment $payment): void;

    public function addAllocation(PaymentAllocation $allocation): void;

    public function removeAllocationsOf(Payment $payment): void;
}

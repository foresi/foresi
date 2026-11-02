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
     * What is still to pay: below zero when it is overpaid.
     */
    public function remainingOf(Invoice $invoice): Money;

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

    public function add(Payment $payment): void;

    public function addAllocation(PaymentAllocation $allocation): void;

    public function removeAllocationsOf(Payment $payment): void;
}

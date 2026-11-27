<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract;

use Foresi\Shared\Domain\Currency;

/**
 * Payments of invoices from bank movements, for the module that owns the
 * movements. Invoicing keeps the payment and its allocations; the movement is
 * named by id only.
 *
 * The locks go in one order everywhere: the movement first, then its invoices by
 * ascending id, as recording a payment takes them.
 */
interface BankPayments
{
    /**
     * Takes the row of the invoice for this transaction. Call it inside a
     * transaction, the invoices by ascending id.
     *
     * @throws BankPaymentRefused with UNKNOWN_INVOICE
     */
    public function lock(string $invoiceId): void;

    /**
     * Whether the invoice has nothing left to pay, or with $refund nothing to
     * refund, read under its lock.
     *
     * @throws BankPaymentRefused with UNKNOWN_INVOICE
     */
    public function isSettled(string $invoiceId, bool $refund): bool;

    /**
     * Records the payment of the movement, under the locks of its invoices.
     *
     * @return string the payment's id
     *
     * @throws BankPaymentRefused nothing is stored
     */
    public function record(BankPayment $payment): string;

    /**
     * Cancels the payment; the release of its movement is told in the same transaction.
     */
    public function cancel(string $paymentId): void;

    /**
     * Whether a payment not cancelled came from the movement.
     */
    public function hasLivePayment(string $bankMovementId): bool;

    /**
     * The numbers of the invoices each payment pays, in one query for all of them.
     *
     * @param list<string> $paymentIds
     *
     * @return array<string, array<string, string>> number by invoice id, by payment id
     */
    public function invoiceNumbers(array $paymentIds): array;

    /**
     * The most minor units the firm may write off on an invoice in the currency,
     * as the firm's country says, or null when the country gives no limit.
     */
    public function settlementLimit(Currency $currency): ?int;
}

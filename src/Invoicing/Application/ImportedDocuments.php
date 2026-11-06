<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Payment;
use App\Party\Domain\LegalEntity;

/**
 * The imported documents and payments of the active tenant. Call remove() and
 * removePayment() inside TransactionalWork.
 */
interface ImportedDocuments
{
    public function find(LegalEntity $firm, string $source, string $externalId): ?Invoice;

    /**
     * @return list<Invoice> in the order of their numbers
     */
    public function ofRun(string $runId): array;

    public function findPayment(LegalEntity $firm, string $source, string $externalId): ?Payment;

    /**
     * @return list<Payment> in the order they were received
     */
    public function paymentsOfRun(string $runId): array;

    /**
     * What else was recorded against a document: payments allocated to it, sends of
     * it, documents correcting it, taxing a payment of it or deducting it. What its
     * own run brought along does not count.
     *
     * @return list<string> the reasons, as UndoBlocker names them
     */
    public function dependents(Invoice $invoice): array;

    /**
     * What else was recorded against a payment: tax documents on it that its own run
     * did not bring.
     *
     * @return list<string> the reasons, as UndoBlocker names them
     */
    public function paymentDependents(Payment $payment): array;

    /**
     * Deletes the document and its lines for good.
     */
    public function remove(Invoice $invoice): void;

    /**
     * Deletes the payment and its allocations for good.
     */
    public function removePayment(Payment $payment): void;
}

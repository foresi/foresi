<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Purchasing\Domain\ReceivedDocumentPayment;

/**
 * The payments of the received documents of the active tenant.
 */
interface ReceivedDocumentPayments
{
    public function add(ReceivedDocumentPayment $payment): void;

    /**
     * Removes the payment for good, saved with the unit of work; the audit log keeps it.
     */
    public function remove(ReceivedDocumentPayment $payment): void;

    /**
     * @return list<ReceivedDocumentPayment> by the day paid, then as recorded
     */
    public function ofDocument(string $documentId): array;

    /**
     * What the payments of each document add up to, in minor units of its
     * currency, and the day of the latest; a document without one is left out.
     *
     * @param list<string> $documentIds
     *
     * @return array<string, array{paid: int, last: \DateTimeImmutable}> by document id
     */
    public function sums(array $documentIds): array;
}

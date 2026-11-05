<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Reads about advance invoices of the active tenant and the tax documents on their
 * payments.
 */
interface AdvanceDocuments
{
    /**
     * The tax document on a payment of an advance, in the trash too: the pair is
     * taken for good.
     *
     * @return string|null its id
     */
    public function taxDocumentOf(string $advanceId, string $paymentId): ?string;

    /**
     * The payments of advance invoices out of the trash that are not cancelled and
     * have no tax document, oldest payment first. Whether VAT arose on them is for
     * the caller to tell.
     *
     * @return list<array{advanceId: string, paymentId: string}>
     */
    public function paidWithoutTaxDocument(): array;

    /**
     * The tax documents on a payment, in the trash too, by id.
     *
     * @return list<string> ids
     */
    public function taxDocumentsOfPayment(string $paymentId): array;

    /**
     * Claims a tax document or advance invoice for the invoice that deducts it, in
     * the caller's transaction: refused when another invoice holds it.
     *
     * @return bool whether the invoice holds it now
     */
    public function claimDeduction(string $documentId, string $invoiceId): bool;

    /**
     * Gives up what an invoice claimed, except these.
     *
     * @param list<string> $keep
     */
    public function releaseDeductions(string $invoiceId, array $keep): void;

    /**
     * What a firm's customer has to deduct: tax documents on payments not cancelled,
     * and advance invoices out of the trash, that no other invoice deducts. Whether an
     * advance created no VAT is for the caller to tell.
     *
     * @return array{taxDocuments: list<string>, advances: list<string>} ids, oldest first
     */
    public function deductibleFor(string $firmId, string $customerId, ?string $except): array;
}

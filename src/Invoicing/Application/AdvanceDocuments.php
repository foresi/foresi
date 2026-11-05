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
}

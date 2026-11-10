<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application\Query;

use App\Purchasing\Domain\PurchaseTaxTreatment;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Purchasing\Domain\ReceivedDocumentState;
use App\Shared\Domain\Money;

/**
 * One line of the list of received documents, read without loading the
 * document: the numbers, the supplier as the document names it, the day that
 * places it in its month, its total in its currency and its VAT in CZK.
 */
final readonly class ReceivedDocumentRow
{
    public function __construct(
        public string $id,
        public string $firmId,
        public string $firmName,
        public ReceivedDocumentState $state,
        public ReceivedDocumentKind $kind,
        public PurchaseTaxTreatment $treatment,
        public ?string $evidenceNumber,
        public string $supplierName,
        public string $supplierNumber,
        public ?\DateTimeImmutable $issueDate,
        public ?\DateTimeImmutable $taxPointDate,
        public ?\DateTimeImmutable $receivedDate,
        public ?Money $total,
        public ?Money $vatCzk,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $deletedAt,
        public ?string $deletedBy,
        public ?string $deletedReason,
    ) {
    }

    public function isTrashed(): bool
    {
        return $this->deletedAt instanceof \DateTimeImmutable;
    }

    /**
     * The tax point, or the issue date of a document from abroad (ReceivedDocumentFilter).
     */
    public function monthDay(): ?\DateTimeImmutable
    {
        return ReceivedDocumentFilter::monthDay($this->kind, $this->treatment, $this->issueDate, $this->taxPointDate);
    }
}

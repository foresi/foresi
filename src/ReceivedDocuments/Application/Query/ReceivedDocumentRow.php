<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ReceivedDocuments\Application\Query;

use Foresi\ReceivedDocuments\Domain\DocumentSource;
use Foresi\ReceivedDocuments\Domain\PurchaseTaxTreatment;
use Foresi\ReceivedDocuments\Domain\ReadingState;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentKind;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentState;
use Foresi\Shared\Domain\Money;

/**
 * One line of the list of received documents, read without loading the
 * document: the numbers, the supplier as the document names it, the day that
 * places it in its month, its total in its currency and its VAT in CZK, how
 * it came in and where the reading of its file stands.
 */
final readonly class ReceivedDocumentRow
{
    public function __construct(
        public string $id,
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
        /** The contact the supplier was picked from, null for one typed by hand. */
        public ?string $supplierId = null,
        public DocumentSource $source = DocumentSource::Manual,
        /** Null for a document whose file was never read. */
        public ?ReadingState $readingState = null,
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

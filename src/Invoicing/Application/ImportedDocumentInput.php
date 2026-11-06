<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRegime;

/**
 * A document another system issued, as it was there. The document names its series
 * and its number, and its customer copy as printed; the issuer, the regime and the
 * rounding are those it was issued with, not the firm's today. The source and the
 * id there name it for good, and the run groups what one import brought, so it can
 * be undone together.
 */
final readonly class ImportedDocumentInput
{
    /**
     * @param string      $source      the system it came from, such as "idoklad"
     * @param string      $runId       the id of the import that brings it
     * @param string|null $originalPdf the bytes of the PDF the source drew
     */
    public function __construct(
        public InvoiceInput $document,
        public DocumentParty $issuer,
        public VatRegime $regime,
        public TotalRounding $rounding,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $deliveredAt,
        public ?\DateTimeImmutable $exportedAt,
        public ImportedTotals $expected,
        public string $source,
        public string $externalId,
        public string $runId,
        public ?string $originalPdf = null,
    ) {
    }
}

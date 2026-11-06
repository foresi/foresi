<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Pdf;

use App\Invoicing\Application\InvoicePdf;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\IssuedDocument;
use App\Invoicing\Application\OriginalDocuments;
use App\Invoicing\Application\OriginalDocumentUnreadable;
use App\Invoicing\Application\RenderedPdf;
use App\Invoicing\Application\UndrawableDocument;
use App\Invoicing\Domain\NumberKey;

/**
 * An imported document that kept the PDF its source drew is that PDF, wherever the
 * document is shown, packed or sent; every other document is drawn. An original
 * that cannot be read is not replaced by a drawing, which would pass for it.
 */
final readonly class OriginalFirstInvoicePdf implements InvoicePdf
{
    public function __construct(private InvoicePdf $drawn, private InvoiceStore $store, private OriginalDocuments $originals)
    {
    }

    public function render(IssuedDocument $document): RenderedPdf
    {
        $invoice = $this->store->get($document->id);
        $key = $invoice->originalPdfKey();
        if (null === $key) {
            return $this->drawn->render($document);
        }
        try {
            // Both are kept together; a missing hash matches no file.
            $bytes = $this->originals->read($invoice->tenantId()->toString(), $key, $invoice->originalPdfSha256() ?? '');
        } catch (OriginalDocumentUnreadable $unreadable) {
            throw new UndrawableDocument('The original of the document cannot be read.', 0, $unreadable);
        }

        return new RenderedPdf($bytes, NumberKey::of($document->number).'.pdf');
    }
}

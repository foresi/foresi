<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Contract\IssuedDocument;

/**
 * The printed form of an issued document, in the language of the document, read
 * from its frozen snapshots and stored amounts.
 */
interface InvoicePdf
{
    public function render(IssuedDocument $document): RenderedPdf;
}

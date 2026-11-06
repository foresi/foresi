<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Writes the download of a document list.
 */
interface DocumentListWriter
{
    /**
     * @param list<InvoiceSumLine>     $rates     one pair of columns, base and VAT, for each treatment and rate
     * @param iterable<ListedDocument> $documents
     * @param string                   $locale    the language of the headers and labels
     */
    public function write(array $rates, iterable $documents, ListFileFormat $format, string $path, string $locale): void;
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Downloads exactly what a document list shows, over all its pages: the same filter
 * gives the rows and a pair of columns for each treatment and rate
 * among them. The documents are read in batches, so a long list never sits in
 * memory at once.
 */
final readonly class ExportDocumentList
{
    public const int BATCH = 500;

    /**
     * @param positive-int $batch
     */
    public function __construct(
        private ListedDocuments $documents,
        private DocumentListWriter $writer,
        private int $batch = self::BATCH,
    ) {
    }

    public function write(InvoiceFilter $filter, ListFileFormat $format, string $path, string $locale): void
    {
        $this->writer->write($this->documents->listedRates($filter), $this->batches($this->documents->listedIds($filter)), $format, $path, $locale);
    }

    /**
     * @param list<string> $ids
     *
     * @return \Generator<int, ListedDocument>
     */
    private function batches(array $ids): \Generator
    {
        foreach (array_chunk($ids, $this->batch) as $batch) {
            yield from $this->documents->listedDocuments($batch);
        }
    }
}

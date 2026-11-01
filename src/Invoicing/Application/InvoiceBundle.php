<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Party\Domain\LegalEntity;

/**
 * The PDFs of a period in one ZIP, for the accountant. Each document is read, drawn
 * and written to disk on its own, and reading the next starts the session over, so
 * a large period needs no more memory than a small one: only the archive takes the
 * files together, when it closes.
 */
final readonly class InvoiceBundle
{
    public function __construct(
        private InvoiceStore $store,
        private IssuedDocumentFactory $documents,
        private InvoicePdf $pdf,
    ) {
    }

    /**
     * @param string $path the ZIP to write; for an empty period nothing is written there
     *
     * @return int how many documents it holds
     */
    public function writeTo(string $path, LegalEntity $firm, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $ids = $this->store->idsForPeriod($firm, $from, $to);
        if ([] === $ids) {
            return 0;
        }

        $parts = $path.'.parts';
        if (!mkdir($parts, 0o700)) {
            throw new \RuntimeException('The working directory cannot be created.');
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            rmdir($parts);

            throw new \RuntimeException('The archive cannot be created.');
        }

        $files = [];
        try {
            foreach ($ids as $id) {
                $rendered = $this->pdf->render($this->documents->of($id));
                $file = $parts.'/'.\count($files).'.pdf';
                file_put_contents($file, $rendered->bytes);
                $files[] = $file;
                $zip->addFile($file, $rendered->fileName);
            }
            if (!$zip->close()) {
                throw new \RuntimeException('The archive cannot be written.');
            }
        } finally {
            array_map(unlink(...), $files);
            rmdir($parts);
        }

        return \count($files);
    }
}

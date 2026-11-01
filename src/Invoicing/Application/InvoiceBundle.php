<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Party\Domain\LegalEntity;
use Psr\Log\LoggerInterface;

/**
 * The PDFs of a period in one ZIP, for the accountant. Each document is read, drawn
 * and written to disk on its own, and reading the next starts the session over, so
 * a large period needs no more memory than a small one: only the archive takes the
 * files together, when it closes. Every document gets its own time limit, so a long
 * period finishes and a stuck document does not hang the request for good.
 *
 * All or nothing: an accountant must not receive a period with a document missing
 * without noticing, so a document that cannot be drawn stops the archive, after the
 * rest were tried, and is named.
 */
final readonly class InvoiceBundle
{
    private const int SECONDS_PER_DOCUMENT = 30;
    private const int SECONDS_TO_CLOSE = 300;

    public function __construct(
        private InvoiceStore $store,
        private IssuedDocumentFactory $documents,
        private InvoicePdf $pdf,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $path the ZIP to write; for an empty period nothing is written there
     *
     * @return int how many documents it holds
     *
     * @throws BundleIncomplete  when a document cannot be drawn; nothing usable is left at the path
     * @throws \RuntimeException when the archive cannot be written
     */
    public function writeTo(string $path, LegalEntity $firm, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $ids = $this->store->idsForPeriod($firm, $from, $to);
        if ([] === $ids) {
            return 0;
        }

        $parts = $path.'.parts';
        if (!@mkdir($parts, 0o700)) {
            throw new \RuntimeException('The working directory cannot be created.');
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            rmdir($parts);

            throw new \RuntimeException('The archive cannot be created.');
        }

        $files = [];
        $failed = [];
        try {
            foreach ($ids as $id) {
                set_time_limit(self::SECONDS_PER_DOCUMENT);
                $document = $this->documents->of($id);
                try {
                    $rendered = $this->pdf->render($document);
                } catch (\Exception $failure) {
                    $this->logger->error('A document of a bundle cannot be drawn.', ['id' => $id, 'exception' => $failure]);
                    $failed[] = $document->number;

                    continue;
                }
                $file = $parts.'/'.\count($files).'.pdf';
                $files[] = $file;
                if (\strlen($rendered->bytes) !== file_put_contents($file, $rendered->bytes) || !$zip->addFile($file, $rendered->fileName)) {
                    throw new \RuntimeException('The archive cannot take a document.');
                }
            }
            if ([] !== $failed) {
                $zip->unchangeAll();
                $zip->close();

                throw new BundleIncomplete($failed);
            }
            set_time_limit(self::SECONDS_TO_CLOSE);
            if (!$zip->close()) {
                throw new \RuntimeException('The archive cannot be written.');
            }
        } finally {
            array_map(static fn (string $file): bool => @unlink($file), $files);
            rmdir($parts);
        }

        return \count($files);
    }
}

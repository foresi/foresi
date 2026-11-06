<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentKind;
use App\Party\Domain\LegalEntity;
use Psr\Log\LoggerInterface;

/**
 * The documents of one firm and month in one ZIP, for the accountant: a folder per
 * kind, each document as a PDF with its electronic file beside it, and a list of
 * them with a VAT recap. A tax document falls in the month of its tax point, an
 * advance invoice, which has none, in the month of its issue. The trash stays out.
 *
 * Each document is read, drawn and written to disk on its own, and reading the next
 * starts the session over, so a long month needs no more memory than a short one.
 * Every document gets its own time limit, so a stuck one does not hang the request
 * for good.
 *
 * All or nothing: an accountant must not receive a month with a document missing
 * without noticing, so a document that cannot be drawn stops the archive, after the
 * rest were tried, and is named. Only a finished archive marks its documents exported.
 */
final readonly class InvoiceBundle
{
    private const int SECONDS_PER_DOCUMENT = 30;
    private const int SECONDS_TO_CLOSE = 300;

    public function __construct(
        private InvoiceStore $store,
        private IssuedDocumentFactory $documents,
        private InvoicePdf $pdf,
        private InvoicingCountries $countries,
        private AccountantPackFormat $format,
        private DocumentExports $exports,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param \DateTimeImmutable $month   any day of it
     * @param bool               $pending only documents never exported or edited after their export
     *
     * @return array<string, int> how many documents of each kind the pack would hold, every kind named
     */
    public function counts(LegalEntity $firm, \DateTimeImmutable $month, bool $pending): array
    {
        $counts = array_fill_keys(array_map(static fn (DocumentKind $kind): string => $kind->value, DocumentKind::cases()), 0);
        foreach ($this->selection($firm, $month, $pending) as $kind) {
            ++$counts[$kind->value];
        }

        return $counts;
    }

    public function fileName(LegalEntity $firm, \DateTimeImmutable $month): string
    {
        return $this->format->packName($firm, $month);
    }

    /**
     * Writes the pack and marks what it holds exported.
     *
     * @param string             $path    the ZIP to write; for an empty month nothing is written there
     * @param \DateTimeImmutable $month   any day of it
     * @param bool               $pending only documents never exported or edited after their export
     *
     * @return int how many documents it holds
     *
     * @throws BundleIncomplete  when a document cannot be drawn; nothing usable is left at the path and nothing is marked
     * @throws \RuntimeException when the archive cannot be written
     */
    public function writeTo(string $path, LegalEntity $firm, \DateTimeImmutable $month, bool $pending, string $locale): int
    {
        $ids = array_keys($this->selection($firm, $month, $pending));
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
        $listed = [];
        $names = [];
        // The limits below are for this archive only; whatever ran before gets its own back.
        $limit = (int) ini_get('max_execution_time');
        try {
            foreach ($ids as $id) {
                set_time_limit(self::SECONDS_PER_DOCUMENT);
                $document = $this->documents->of($id);
                $state = $this->store->get($id)->exportState();
                try {
                    $rendered = $this->pdf->render($document);
                    $electronic = $this->countries->for($document->issuer->address->countryCode)->electronicFile($document);
                } catch (\Exception $failure) {
                    $this->logger->error('A document of a bundle cannot be drawn.', ['id' => $id, 'exception' => $failure]);
                    $failed[] = $document->number;

                    continue;
                }
                $listed[] = new AccountantDocument($document, $state);
                $name = $this->unique($this->format->folder($document->kind, $locale).'/'.$this->format->baseName($document, $locale), $document->number, $names);
                $this->add($zip, $parts, $files, $rendered->bytes, $name.'.pdf');
                if ($electronic instanceof ElectronicFile) {
                    $this->add($zip, $parts, $files, $electronic->content, $name.'.isdoc');
                }
            }
            if ([] !== $failed) {
                $zip->unchangeAll();
                $zip->close();

                throw new BundleIncomplete($failed);
            }
            $list = $parts.'/list.xlsx';
            $files[] = $list;
            $home = $this->countries->for($firm->address()->countryCode)->homeCurrency();
            $this->format->writeList($list, $listed, VatRecap::of(array_map(static fn (AccountantDocument $listed): IssuedDocument => $listed->document, $listed), $home), $home, $locale);
            if (!$zip->addFile($list, $this->format->listName($locale))) {
                throw new \RuntimeException('The archive cannot take the list.');
            }
            set_time_limit(self::SECONDS_TO_CLOSE);
            if (!$zip->close()) {
                throw new \RuntimeException('The archive cannot be written.');
            }
        } finally {
            set_time_limit($limit);
            array_map(static fn (string $file): bool => @unlink($file), $files);
            rmdir($parts);
        }

        // Only a whole archive counts as given to the accountant; one that cannot be marked is not sent.
        try {
            $this->exports->markExported($ids);
        } catch (\Throwable $failure) {
            @unlink($path);

            throw $failure;
        }

        return \count($ids);
    }

    /**
     * @return array<string, DocumentKind>
     */
    private function selection(LegalEntity $firm, \DateTimeImmutable $month, bool $pending): array
    {
        $first = $month->modify('first day of this month');

        return $this->store->forAccountant($firm, $first, $first->modify('last day of this month'), $pending);
    }

    /**
     * @param list<string> $files the working files so far, which takes this one
     */
    private function add(\ZipArchive $zip, string $parts, array &$files, string $bytes, string $name): void
    {
        $file = $parts.'/'.\count($files);
        $files[] = $file;
        if (\strlen($bytes) !== file_put_contents($file, $bytes) || !$zip->addFile($file, $name)) {
            throw new \RuntimeException('The archive cannot take a document.');
        }
    }

    /**
     * Two documents with the same name are told apart by the number of the later one.
     *
     * @param list<string> $taken
     */
    private function unique(string $name, string $number, array &$taken): string
    {
        // A firm numbers each document once, so its number always tells two apart.
        $candidate = \in_array($name, $taken, true) ? $name.'_'.preg_replace('/[^A-Za-z0-9-]+/', '-', $number) : $name;
        $taken[] = $candidate;

        return $candidate;
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Pdf;

use Foresi\Invoicing\Application\OriginalDocuments;
use Foresi\Invoicing\Application\OriginalDocumentUnreadable;
use Foresi\Invoicing\Application\SentFileUnreadable;
use Foresi\Invoicing\Infrastructure\Mail\DiskSentFiles;

/**
 * Original PDFs in one directory, never below public/, kept the way
 * sent PDFs are: an invoice id names the file as a send id does there. Back the
 * directory up with the database.
 */
final readonly class DiskOriginalDocuments implements OriginalDocuments
{
    private DiskSentFiles $files;

    public function __construct(string $root)
    {
        $this->files = new DiskSentFiles($root);
    }

    public function store(string $invoiceId, string $bytes): string
    {
        return $this->files->store($invoiceId, $bytes);
    }

    public function read(string $invoiceId, string $key, string $sha256): string
    {
        try {
            return $this->files->read($invoiceId, $key, $sha256);
        } catch (SentFileUnreadable $unreadable) {
            throw new OriginalDocumentUnreadable($unreadable->getMessage(), 0, $unreadable);
        }
    }

    public function remove(string $invoiceId): void
    {
        $this->files->remove($invoiceId);
    }
}

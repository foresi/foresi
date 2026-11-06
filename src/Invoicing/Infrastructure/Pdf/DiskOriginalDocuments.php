<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Pdf;

use App\Invoicing\Application\OriginalDocuments;
use App\Invoicing\Application\OriginalDocumentUnreadable;
use App\Invoicing\Application\SentFileUnreadable;
use App\Invoicing\Infrastructure\Mail\DiskSentFiles;

/**
 * Original PDFs under one directory per tenant, never below public/, kept the way
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

    public function store(string $tenantId, string $invoiceId, string $bytes): string
    {
        return $this->files->store($tenantId, $invoiceId, $bytes);
    }

    public function read(string $tenantId, string $key, string $sha256): string
    {
        try {
            return $this->files->read($tenantId, $key, $sha256);
        } catch (SentFileUnreadable $unreadable) {
            throw new OriginalDocumentUnreadable($unreadable->getMessage(), 0, $unreadable);
        }
    }

    public function remove(string $tenantId, string $invoiceId): void
    {
        $this->files->remove($tenantId, $invoiceId);
    }
}

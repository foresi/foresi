<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Port: the PDFs of imported documents as their source drew them, kept beside the
 * database. Every key names its document, so a row can never point at the file
 * of another one.
 */
interface OriginalDocuments
{
    /**
     * @return string the key of the file, "{invoiceId}.pdf"
     */
    public function store(string $invoiceId, string $bytes): string;

    /**
     * The bytes, after checking that the key is the document's and the
     * file still has the hash it was stored with.
     *
     * @throws OriginalDocumentUnreadable
     */
    public function read(string $invoiceId, string $key, string $sha256): string;

    /**
     * Removes the file of a document that was never stored or is gone. Missing is fine.
     */
    public function remove(string $invoiceId): void;
}

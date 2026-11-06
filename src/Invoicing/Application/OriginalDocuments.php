<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Port: the PDFs of imported documents as their source drew them, kept beside the
 * database. Every key starts with the tenant's id, so a row can never point into
 * another tenant's files.
 */
interface OriginalDocuments
{
    /**
     * @return string the key of the file, "{tenant}/{invoiceId}.pdf"
     */
    public function store(string $tenantId, string $invoiceId, string $bytes): string;

    /**
     * The bytes, after checking that the key lies in the tenant's storage and the
     * file still has the hash it was stored with.
     *
     * @throws OriginalDocumentUnreadable
     */
    public function read(string $tenantId, string $key, string $sha256): string;

    /**
     * Removes the file of a document that was never stored or is gone. Missing is fine.
     */
    public function remove(string $tenantId, string $invoiceId): void;
}

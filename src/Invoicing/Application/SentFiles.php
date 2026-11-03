<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Port: the PDFs exactly as they were sent, kept beside the database. Every key
 * starts with the tenant's id, so a row can never point into another tenant's
 * files.
 */
interface SentFiles
{
    /**
     * @return string the key of the file, "{tenant}/{sendId}.pdf"
     */
    public function store(string $tenantId, string $sendId, string $bytes): string;

    /**
     * The bytes, after checking that the key lies in the tenant's storage and the
     * file still has the hash it was stored with.
     *
     * @throws SentFileUnreadable
     */
    public function read(string $tenantId, string $key, string $sha256): string;

    /**
     * Removes the file of a send that was never stored. Missing is fine.
     */
    public function remove(string $tenantId, string $sendId): void;
}

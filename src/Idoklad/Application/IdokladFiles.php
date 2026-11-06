<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application;

/**
 * Port: the PDFs of pulled documents, kept beside the database. Every key
 * starts with the tenant's id, so a row can never point into another tenant's
 * files.
 */
interface IdokladFiles
{
    /**
     * @return string the key of the file, "{tenant}/{recordId}.pdf"
     */
    public function store(string $tenantId, string $recordId, string $bytes): string;

    /**
     * The bytes, after checking that the key lies in the tenant's storage and the
     * file still has the hash it was stored with.
     *
     * @throws IdokladFileUnreadable
     */
    public function read(string $tenantId, string $key, string $sha256): string;
}

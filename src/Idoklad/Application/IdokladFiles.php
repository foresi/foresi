<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application;

/**
 * Port: the PDFs of pulled documents, kept beside the database. Every key ends
 * with the record's id, so a row can never point at another record's file.
 */
interface IdokladFiles
{
    /**
     * @return string the key of the file, "{recordId}.pdf"
     */
    public function store(string $recordId, string $bytes): string;

    /**
     * The bytes, after checking that the key names the record's file and the
     * file still has the hash it was stored with. Keys stored before one
     * instance served one customer, "{tenant}/{recordId}.pdf", still read.
     *
     * @throws IdokladFileUnreadable
     */
    public function read(string $recordId, string $key, string $sha256): string;
}

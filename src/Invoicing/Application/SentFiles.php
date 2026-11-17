<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Port: the PDFs exactly as they were sent, kept beside the database. Every key
 * names its send, so a row can never point at the file of another send.
 */
interface SentFiles
{
    /**
     * @return string the key of the file, "{sendId}.pdf"
     */
    public function store(string $sendId, string $bytes): string;

    /**
     * The bytes, after checking that the key is the send's and the
     * file still has the hash it was stored with.
     *
     * @throws SentFileUnreadable
     */
    public function read(string $sendId, string $key, string $sha256): string;

    /**
     * Removes the file of a send that was never stored. Missing is fine.
     */
    public function remove(string $sendId): void;
}

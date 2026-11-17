<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Application;

/**
 * The private store of the bytes of attachments. Every key ends with the
 * attachment's id, so a record can never point at another attachment's file.
 */
interface AttachmentFiles
{
    /**
     * Copies the file into the store and returns the key it is kept under, the
     * attachment's id.
     *
     * @throws \RuntimeException when the file cannot be stored
     */
    public function store(string $attachmentId, string $sourcePath): string;

    /**
     * The local path of the stored file, once its content is proved unchanged.
     * Keys stored before one instance served one customer, "{tenant}/{id}",
     * still read.
     *
     * @throws AttachmentFileUnreadable when it is missing, of another attachment or changed
     */
    public function read(string $attachmentId, string $key, string $sha256): string;

    /**
     * The local path of the stored file without hashing it again, for a part
     * of a file whose whole content is proved on every full read.
     *
     * @throws AttachmentFileUnreadable when it is missing or of another attachment
     */
    public function locate(string $attachmentId, string $key): string;

    public function remove(string $key): void;
}

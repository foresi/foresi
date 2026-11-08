<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Application;

/**
 * The private store of the bytes of attachments, one directory per tenant.
 */
interface AttachmentFiles
{
    /**
     * Copies the file into the store and returns the key it is kept under.
     *
     * @throws \RuntimeException when the file cannot be stored
     */
    public function store(string $tenantId, string $attachmentId, string $sourcePath): string;

    /**
     * The local path of the stored file, once its content is proved unchanged.
     *
     * @throws AttachmentFileUnreadable when it is missing, of another tenant or changed
     */
    public function read(string $tenantId, string $key, string $sha256): string;

    /**
     * The local path of the stored file without hashing it again, for a part
     * of a file whose whole content is proved on every full read.
     *
     * @throws AttachmentFileUnreadable when it is missing or of another tenant
     */
    public function locate(string $tenantId, string $key): string;

    public function remove(string $tenantId, string $key): void;
}

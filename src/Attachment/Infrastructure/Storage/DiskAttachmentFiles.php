<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Infrastructure\Storage;

use App\Attachment\Application\AttachmentFiles;
use App\Attachment\Application\AttachmentFileUnreadable;

/**
 * Attachments under one directory per tenant, never below public/, each file
 * named by its attachment id only. Back the directory up with the database: an
 * attachment without its file cannot be opened.
 */
final readonly class DiskAttachmentFiles implements AttachmentFiles
{
    private const string KEY = '/^([0-9a-f-]{36})\/([0-9a-f-]{36})$/D';

    public function __construct(private string $root)
    {
    }

    public function store(string $tenantId, string $attachmentId, string $sourcePath): string
    {
        $key = $tenantId.'/'.$attachmentId;
        if (1 !== preg_match(self::KEY, $key)) {
            throw new \InvalidArgumentException('A tenant and an attachment are named by their ids.');
        }
        $directory = $this->root.'/'.$tenantId;
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException('The directory of attachments cannot be created.');
        }
        // A directory that already exists may have been created with wider permissions.
        chmod($directory, 0o700);

        // Private before any byte is written, so the content is never readable by others.
        $temporary = tempnam($directory, 'upload');
        if (false === $temporary) {
            throw new \RuntimeException('The attachment cannot be stored.');
        }
        if (!chmod($temporary, 0o600) || !copy($sourcePath, $temporary) || !rename($temporary, $this->root.'/'.$key)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw new \RuntimeException('The attachment cannot be stored.');
        }

        return $key;
    }

    public function read(string $tenantId, string $key, string $sha256): string
    {
        if (1 !== preg_match(self::KEY, $key, $parts) || $parts[1] !== $tenantId) {
            throw new AttachmentFileUnreadable('The attachment does not belong to the tenant.');
        }
        $path = $this->root.'/'.$key;
        $actual = is_file($path) ? hash_file('sha256', $path) : false;
        if (false === $actual) {
            throw new AttachmentFileUnreadable('The attachment is missing.');
        }
        if (!hash_equals($sha256, $actual)) {
            throw new AttachmentFileUnreadable('The attachment changed after it was uploaded.');
        }

        return $path;
    }

    public function remove(string $tenantId, string $key): void
    {
        if (1 === preg_match(self::KEY, $key, $parts) && $parts[1] === $tenantId && is_file($this->root.'/'.$key)) {
            unlink($this->root.'/'.$key);
        }
    }
}

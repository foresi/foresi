<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Infrastructure\Storage;

use App\Attachment\Application\AttachmentFiles;
use App\Attachment\Application\AttachmentFileUnreadable;

/**
 * Attachments in one private directory, never below public/, each file named
 * by its attachment id only. Back the directory up with the database: an
 * attachment without its file cannot be opened. Files uploaded by older
 * versions lie one directory deeper, under a second id.
 */
final readonly class DiskAttachmentFiles implements AttachmentFiles
{
    private const string ID = '/^[0-9a-f-]{36}$/D';
    private const string KEY = '/^(?:[0-9a-f-]{36}\/)?([0-9a-f-]{36})$/D';

    public function __construct(private string $root)
    {
    }

    public function store(string $attachmentId, string $sourcePath): string
    {
        if (1 !== preg_match(self::ID, $attachmentId)) {
            throw new \InvalidArgumentException('An attachment is named by its id.');
        }
        $key = $attachmentId;
        $directory = $this->root;
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

    public function read(string $attachmentId, string $key, string $sha256): string
    {
        $path = $this->locate($attachmentId, $key);
        $actual = hash_file('sha256', $path);
        if (false === $actual) {
            throw new AttachmentFileUnreadable('The attachment is missing.');
        }
        if (!hash_equals($sha256, $actual)) {
            throw new AttachmentFileUnreadable('The attachment changed after it was uploaded.');
        }

        return $path;
    }

    public function locate(string $attachmentId, string $key): string
    {
        if (1 !== preg_match(self::KEY, $key, $parts) || $parts[1] !== $attachmentId) {
            throw new AttachmentFileUnreadable('The file does not belong to the attachment.');
        }
        $path = $this->root.'/'.$key;
        if (!is_file($path)) {
            throw new AttachmentFileUnreadable('The attachment is missing.');
        }

        return $path;
    }

    public function remove(string $key): void
    {
        if (1 === preg_match(self::KEY, $key) && is_file($this->root.'/'.$key)) {
            unlink($this->root.'/'.$key);
        }
    }
}

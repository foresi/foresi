<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Application;

/**
 * A file that arrived for a record: where it lies on disk, the name the
 * browser gave it and the size it reported. Its type is never taken from the
 * browser; the store sniffs it from the bytes.
 */
final readonly class AttachmentUpload
{
    /**
     * @param bool $received false when the upload did not arrive whole
     * @param bool $tooBig   true when the web server already refused it for its size
     */
    public function __construct(
        public string $path,
        public string $clientName,
        public int $size,
        public bool $received = true,
        public bool $tooBig = false,
    ) {
    }
}

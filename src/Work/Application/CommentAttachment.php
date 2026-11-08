<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * A file attached to a comment, enough to list and link it.
 */
final readonly class CommentAttachment
{
    /**
     * @param int $size in bytes
     */
    public function __construct(
        public string $id,
        public string $fileName,
        public string $mediaType,
        public int $size,
    ) {
    }
}

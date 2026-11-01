<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Pdf;

/**
 * A file attached to a PDF/A-3 document. It is always written as an
 * alternative representation of the document (AFRelationship Alternative).
 */
final readonly class EmbeddedFile
{
    public function __construct(
        public string $name,
        public string $content,
        public string $mime,
    ) {
        if ('' === $name || '' === $content) {
            throw new \InvalidArgumentException('An embedded file needs a name and content.');
        }
    }
}

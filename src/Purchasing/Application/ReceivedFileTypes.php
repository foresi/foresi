<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application;

/**
 * The one rule for the files a received document takes in, whether a person
 * sends them over the API and MCP or they arrive attached to a mail: one of
 * the types a reader can open, told by the bytes and never by what the sender
 * says, and at most MAX_BYTES.
 */
final readonly class ReceivedFileTypes
{
    public const int MAX_BYTES = 10 * 1024 * 1024;

    private const array TYPES = [
        'application/pdf',
        'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence',
        'application/xml', 'text/xml',
    ];

    /**
     * Whether the bytes are a file of an accepted type: a PDF, an image (PNG,
     * JPEG, GIF, WebP, HEIC), an ISDOC file or XML. An .isdocx is a ZIP, told
     * by its extension.
     */
    public function accepts(string $bytes, string $fileName): bool
    {
        $type = new \finfo(\FILEINFO_MIME_TYPE)->buffer($bytes);
        if ('application/zip' === $type) {
            return 'isdocx' === strtolower(pathinfo($fileName, \PATHINFO_EXTENSION));
        }

        return \in_array($type, self::TYPES, true);
    }
}

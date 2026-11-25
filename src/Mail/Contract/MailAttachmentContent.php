<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * The decoded bytes of one attachment of a stored message.
 */
final readonly class MailAttachmentContent
{
    public function __construct(
        public string $name,
        public string $contentType,
        public string $bytes,
    ) {
    }
}

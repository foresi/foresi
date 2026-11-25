<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * An attachment of a stored message, without its bytes. The name and the type
 * are the sender's word.
 */
final readonly class MailAttachment
{
    public function __construct(
        public int $index,
        public string $name,
        public string $contentType,
        public int $size,
    ) {
    }
}

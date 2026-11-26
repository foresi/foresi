<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Contract;

/**
 * The decoded bytes of an attachment of an inbox item. Serve them as a
 * download only; the name and the type are the sender's word.
 */
final readonly class InboxAttachmentContent
{
    public function __construct(
        public string $name,
        public string $mime,
        public string $bytes,
    ) {
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Contract;

/**
 * An attachment of the mail of an inbox item, without its bytes. The name and
 * the type are the sender's word.
 */
final readonly class InboxAttachment
{
    public function __construct(
        public int $index,
        public string $name,
        public string $mime,
        public int $size,
    ) {
    }
}

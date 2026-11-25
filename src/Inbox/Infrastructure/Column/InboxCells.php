<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Column;

/**
 * One row of the inbox as its cells show it, every cell already in words.
 */
final readonly class InboxCells
{
    public function __construct(
        public string $sender,
        public string $contact,
        public string $subject,
        public string $received,
        public string $outputs,
    ) {
    }
}

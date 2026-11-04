<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * One message of the operator mailbox: its UID there and its raw bytes.
 */
final readonly class MailboxItem
{
    public function __construct(
        public string $uid,
        public string $raw,
    ) {
    }
}

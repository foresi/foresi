<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Contract;

/**
 * A record that came of an inbox item, such as the deal it was linked to.
 */
final readonly class InboxOutput
{
    public function __construct(
        public string $kind,
        public string $recordId,
        public \DateTimeImmutable $addedAt,
    ) {
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

/**
 * Asks the public registers about a party again, in the background, after a
 * write that did not wait for them (an import). The answer is stored under
 * the audit correlation of that write, so it counts as part of it: an undo of
 * the import is not blocked by it.
 */
final readonly class RecheckRegistry
{
    public function __construct(
        public string $partyId,
        public ?string $correlationId = null,
    ) {
    }
}

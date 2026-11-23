<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * One occurrence of a planned item a document is, or may be, paired with, for a screen: the item's name, the
 * occurrence day and where the item's page is.
 */
final readonly class PairingOption
{
    public function __construct(
        public string $plannedItemId,
        public string $name,
        public \DateTimeImmutable $occurrenceOn,
        public string $url,
    ) {
    }
}

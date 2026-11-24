<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Shared\Domain\Money;

/**
 * The part of a line's cost that belongs to one recipient, and where it came
 * from (line, document, planned_item, supplier or unassigned). A null
 * recipient is the cost nobody has claimed yet. A share from a planned item
 * names the item and the day of the occurrence (YYYY-MM-DD).
 */
final readonly class ShareView
{
    public function __construct(
        public ?string $recipient,
        public ?string $recipientName,
        public Money $amountCzk,
        public string $source,
        public ?string $plannedItemId = null,
        public ?string $occurrenceOn = null,
    ) {
    }
}

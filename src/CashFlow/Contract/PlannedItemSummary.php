<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * A planned item as a document module shows it beside a document.
 */
final readonly class PlannedItemSummary
{
    /**
     * @param string $url where a person opens the planned item
     */
    public function __construct(
        public string $id,
        public string $name,
        public FlowDirection $direction,
        public ?string $counterpartyId,
        public bool $recurring,
        public string $url,
    ) {
    }
}

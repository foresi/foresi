<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Contract\PlannedItemDirectory;
use Foresi\CashFlow\Contract\PlannedItemSummary;

/**
 * Other modules look planned items up through Foresi\CashFlow\Contract\PlannedItemDirectory.
 */
final readonly class PlannedItemsDirectory implements PlannedItemDirectory
{
    public function __construct(private CashFlowStore $store, private PlannedOccurrences $occurrences)
    {
    }

    public function one(string $id): ?PlannedItemSummary
    {
        $item = $this->store->plannedItem($id);
        if (!$item instanceof \Foresi\CashFlow\Domain\PlannedItem) {
            return null;
        }

        return new PlannedItemSummary(
            $item->id(),
            $item->name(),
            $item->direction(),
            $item->counterparty()?->id(),
            $item->isRecurring(),
            '/api/v1/cash-flow/planned-items/'.$item->id(),
        );
    }

    public function occurrenceHolding(string $id, \DateTimeImmutable $day): ?\DateTimeImmutable
    {
        $item = $this->store->plannedItem($id);

        return $item instanceof \Foresi\CashFlow\Domain\PlannedItem ? $this->occurrences->holding($item, $day) : null;
    }
}

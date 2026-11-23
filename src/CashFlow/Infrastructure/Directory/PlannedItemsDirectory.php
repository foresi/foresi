<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Directory;

use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\PlannedOccurrences;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\PlannedItemDirectory;
use Foresi\CashFlow\Contract\PlannedItemSummary;
use Foresi\CashFlow\Domain\PlannedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Other modules look planned items up through Foresi\CashFlow\Contract\PlannedItemDirectory.
 * The url of an item is its sheet in the planned income or planned costs screen.
 */
final readonly class PlannedItemsDirectory implements PlannedItemDirectory
{
    public function __construct(private CashFlowStore $store, private PlannedOccurrences $occurrences, private UrlGeneratorInterface $urls)
    {
    }

    public function one(string $id): ?PlannedItemSummary
    {
        $item = $this->store->plannedItem($id);
        if (!$item instanceof PlannedItem) {
            return null;
        }

        return new PlannedItemSummary(
            $item->id(),
            $item->name(),
            $item->direction(),
            $item->counterparty()?->id(),
            $item->isRecurring(),
            $this->urls->generate(FlowDirection::In === $item->direction() ? 'app_cash_flow_planned_income_item' : 'app_cash_flow_planned_costs_item', ['id' => $item->id()]),
        );
    }

    public function occurrenceHolding(string $id, \DateTimeImmutable $day): ?\DateTimeImmutable
    {
        $item = $this->store->plannedItem($id);

        return $item instanceof PlannedItem ? $this->occurrences->holding($item, $day) : null;
    }
}

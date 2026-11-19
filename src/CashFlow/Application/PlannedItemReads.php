<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Domain\PlannedItem;

/**
 * The planned items as the API and MCP read them; only the owners and
 * members of the organisation read.
 */
final readonly class PlannedItemReads
{
    public function __construct(private CashFlowAccess $access, private CashFlowStore $store)
    {
    }

    /**
     * @return list<PlannedItem> the firm's items, oldest first
     *
     * @throws CashFlowRefused
     */
    public function all(): array
    {
        $this->access->denyUnlessStaff();

        return $this->store->plannedItemsOf($this->access->firm()->id());
    }

    /**
     * @throws CashFlowRefused
     */
    public function one(string $id): PlannedItem
    {
        $this->access->denyUnlessStaff();

        return $this->store->plannedItem($id) ?? throw CashFlowRefused::notFound('The organisation has no planned item with this id. Find it with foresi_list_planned_items.');
    }
}

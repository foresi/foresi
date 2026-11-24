<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * Lets another module's screen set the counterparty of a planned item. It takes the same path as a change
 * of the planned item, so the same checks, event and re-pairing of the counterparty's documents apply.
 */
interface PlannedItemCounterparties
{
    /**
     * Cash flow's own refusal is thrown when the caller may not write, the item is unknown or the counterparty is refused.
     */
    public function set(string $plannedItemId, string $counterpartyId): void;
}

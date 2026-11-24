<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * The slots of the screens of Cash flow, filled by SlotProviders of other modules' integrations (ADR 0038).
 *
 * PLANNED_ITEM_SECTIONS: panels of the page of a planned item, after its paired documents.
 * Context: plannedItemId (string). Each item's template is included with the item's data and the
 * planned item's id as "plannedItemId".
 */
final class CashFlowSlots
{
    public const string PLANNED_ITEM_SECTIONS = 'cash_flow.planned_item_sections';

    private function __construct()
    {
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * How the costs paired with a recurring planned item are split among the firm
 * itself and its clients from a month on, or no mapping from that month.
 */
#[Operation(
    name: 'margin.planned_allocations.set',
    method: 'PUT',
    path: '/api/v1/margin/planned-items/{plannedItemId}/allocation',
    tool: 'foresi_set_planned_item_allocation',
    scope: 'margin:write',
    title: 'Map a planned cost to clients',
    description: 'Says who bears the cost of a recurring planned cost in Cash flow, from a month on, so every received document paired with one of its occurrences in that month or later takes the mapping unless the document or its line has a mapping of its own. '
        .'The allocation has a kind and shares as foresi_set_line_allocation takes them: "whole" gives the cost to one recipient; "percent" splits it by percents that add up to exactly 100; "fixed" gives amounts in CZK per occurrence to some recipients and the rest to the one share marked rest. A recipient is "own" or the id of a contact, a client. '
        .'from_month is a calendar month written YYYY-MM in the zone of the instance. A mapping applies from its month on, and the months before it keep what they had; setting a month that already has a mapping replaces it. allocation null ends the mapping from that month. '
        .'The planned item must be a recurring cost (money going out) with a counterparty, the supplier whose documents are paired with it, or the mapping is refused. The shares of the documents paired with the item from that month on are recalculated at once. Answers the versions of the mapping, latest first.',
    response: 'MarginPlannedAllocations',
    staffOnly: true,
)]
final readonly class SetPlannedAllocationInput
{
    /**
     * @param array<mixed>|null $allocation
     */
    public function __construct(
        #[Field('The id of the recurring planned cost, as foresi_list_planned_items returns it.', format: 'uuid')]
        public string $plannedItemId,
        #[Field('The mapping as an object of kind (whole, percent or fixed) and shares, a list of objects of recipient ("own" or a contact id), percent, amount and rest as foresi_set_line_allocation takes them; null ends the mapping from the month.', items: Field::ONE_OBJECT)]
        public ?array $allocation,
        #[Field('The first month the mapping applies to, written YYYY-MM, such as "2026-12".', maxLength: 7)]
        public string $fromMonth,
    ) {
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * Which planned cost a line of a recurring invoice template rebills, or no cost.
 */
#[Operation(
    name: 'margin.rebill_links.set',
    method: 'PUT',
    path: '/api/v1/margin/recurring-lines/{recurringLineId}/rebills',
    tool: 'foresi_set_rebill_link',
    scope: 'margin:write',
    title: 'Rebill a planned cost on a recurring invoice line',
    description: 'Says that a line of a recurring invoice template rebills a planned cost of Cash flow to the customer of the template. Every invoice issued from the template after that pairs the line with the occurrence of the planned cost on the tax point of the invoice, so that a cost the customer bears and that was paid but not rebilled shows as "paid, not rebilled" in the margin. '
        .'Foresi never copies the cost into the line: the price of the line stays what it is. The planned item must be money going out. planned_item_id null removes the link; the pairings of invoices already issued stay. Setting the item the line already rebills changes nothing, another item starts the link over from today (a month before it is never flagged). '
        .'Answers the link with warnings: customer_not_a_recipient means the customer of the template bears no share of the mapping of the planned cost this month, so no invoice would be paired; set the mapping with foresi_set_planned_item_allocation or choose another cost. The warning does not stop the link. The id of a template line is the id of a line of the recurring invoice as foresi_get_recurring_invoice returns it.',
    response: 'MarginRebillLink',
    staffOnly: true,
)]
final readonly class SetRebillLinkInput
{
    public function __construct(
        #[Field('The id of the line of the recurring invoice template.', format: 'uuid')]
        public string $recurringLineId,
        #[Field('The id of the recurring planned cost the line rebills, as foresi_list_planned_items returns it; null removes the link.', format: 'uuid')]
        public ?string $plannedItemId,
    ) {
    }
}

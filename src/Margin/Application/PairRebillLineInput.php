<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * An issued invoice line, typically a hand-written one, that rebills an occurrence of a planned cost.
 */
#[Operation(
    name: 'margin.rebill_pairings.pair',
    method: 'POST',
    path: '/api/v1/margin/rebills',
    tool: 'foresi_pair_rebill_line',
    scope: 'margin:write',
    title: 'Pair an invoice line with the planned cost it rebills',
    description: 'Says that a line of an issued invoice rebills one occurrence of a planned cost of Cash flow, so the occurrence no longer shows as "paid, not rebilled" for the customer of the invoice. Lines issued from a recurring invoice template with a rebill link are paired on issue and need no call; use this for a line written by hand or for another occurrence. '
        .'occurrence_on is any day of the period of an occurrence of the planned item, written YYYY-MM-DD; the occurrence that holds the day is paired (not_an_occurrence when none does). The planned item must be money going out and the customer of the invoice must bear a share of its mapping in the month of that occurrence (customer_not_a_recipient otherwise). A line pairs with one occurrence only (already_paired: unpair it first with foresi_unpair_rebill_line). '
        .'A pairing made by hand is removed when its invoice goes to the trash and is not restored with it. Answers the pairing.',
    status: 201,
    response: 'MarginRebillPairing',
    staffOnly: true,
)]
final readonly class PairRebillLineInput
{
    public function __construct(
        #[Field('The id of the line of an issued invoice.', format: 'uuid')]
        public string $invoiceLineId,
        #[Field('The id of the planned cost the line rebills, as foresi_list_planned_items returns it.', format: 'uuid')]
        public string $plannedItemId,
        #[Field('Any day of the period of an occurrence of the planned cost, written YYYY-MM-DD.', maxLength: 10)]
        public string $occurrenceOn,
    ) {
    }
}

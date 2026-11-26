<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A recurring planned item that stops.
 */
#[Operation(
    name: 'cashflow.planned_items.end',
    method: 'POST',
    path: '/api/v1/cash-flow/planned-items/{plannedItemId}/end',
    tool: 'foresi_end_planned_item',
    scope: 'cashflow:write',
    title: 'End a recurring planned item',
    description: 'Ends a recurring planned item: no occurrence falls after the day given, which replaces its end date or count. A one-off item cannot be ended; change it or delete it.',
    response: 'CashFlowPlannedItem',
)]
final readonly class EndPlannedItemInput
{
    public function __construct(
        #[Field('The id of the planned item.', format: 'uuid')]
        public string $plannedItemId,
        #[Field('The last day an occurrence may fall on, ISO 8601.', format: 'date')]
        public string $on,
    ) {
    }
}

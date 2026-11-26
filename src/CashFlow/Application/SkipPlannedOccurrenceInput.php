<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * One occurrence of a planned item that will not happen.
 */
#[Operation(
    name: 'cashflow.planned_items.skip',
    method: 'POST',
    path: '/api/v1/cash-flow/planned-items/{plannedItemId}/skip',
    tool: 'foresi_skip_planned_occurrence',
    scope: 'cashflow:write',
    title: 'Skip an occurrence of a planned item',
    description: 'Leaves one occurrence of a planned item out of the forecast, such as a rent already paid early or an advance not due this month; the other occurrences stay. The day must be one the item falls on. Skipping a skipped day changes nothing.',
    response: 'CashFlowPlannedItem',
)]
final readonly class SkipPlannedOccurrenceInput
{
    public function __construct(
        #[Field('The id of the planned item.', format: 'uuid')]
        public string $plannedItemId,
        #[Field('The day of the occurrence, ISO 8601.', format: 'date')]
        public string $on,
    ) {
    }
}

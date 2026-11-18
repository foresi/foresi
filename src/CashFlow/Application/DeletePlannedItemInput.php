<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * A planned item deleted with all its occurrences.
 */
#[Operation(
    name: 'cashflow.planned_items.delete',
    method: 'DELETE',
    path: '/api/v1/cash-flow/planned-items/{plannedItemId}',
    tool: 'foresi_delete_planned_item',
    scope: 'cashflow:write',
    title: 'Delete a planned item',
    description: 'Deletes a planned item with all its occurrences; it cannot be restored. To keep it for later, change included to false, or end a recurring item instead.',
    destructive: true,
)]
final readonly class DeletePlannedItemInput
{
    public function __construct(
        #[Field('The id of the planned item.', format: 'uuid')]
        public string $plannedItemId,
    ) {
    }
}

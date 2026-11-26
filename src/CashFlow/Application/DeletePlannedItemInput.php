<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

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
    description: 'Deletes a planned item with all its occurrences; it cannot be restored. Refused with has_pairings while a document is paired with one of its occurrences (auto or confirmed); end a recurring item instead, or unpair the documents first. To keep it for later, change included to false. Proposals that list the item let go of it.',
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

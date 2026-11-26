<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A planned invoice removed from the billing plan of a deal over the API and MCP (ADR 0032).
 */
#[Operation(name: 'sales.billing_entries.delete', method: 'DELETE', path: '/api/v1/sales/deals/{dealId}/billing-plan/{entryId}', tool: 'foresi_delete_billing_entry', scope: 'sales:write', title: 'Remove a planned invoice of a won deal', description: 'Removes a planned invoice from the billing plan of a won deal; the forecast no longer counts it. An invoice or recurring invoice it was linked to stays as it is.', destructive: true, response: 'SalesDeal', staffOnly: true)]
final readonly class DeleteBillingEntryInput
{
    public function __construct(
        #[Field('The id of the deal.', format: 'uuid')]
        public string $dealId,
        #[Field('The id of the planned invoice.', format: 'uuid')]
        public string $entryId,
    ) {
    }
}

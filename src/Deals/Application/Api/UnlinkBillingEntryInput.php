<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A planned invoice of a won deal unlinked from the document that fulfilled it, over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'deals.billing_entries.unlink',
    method: 'DELETE',
    path: '/api/v1/deals/{dealId}/billing-plan/{entryId}/fulfilment',
    tool: 'foresi_unlink_billing_entry',
    scope: 'deals:write',
    title: 'Unlink a planned invoice from its invoice',
    description: 'Removes the link between a planned invoice and the invoice or recurring invoice that fulfilled it; the forecast counts the entry again. The document stays as it is. Unlinking an entry with no link changes nothing.',
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class UnlinkBillingEntryInput
{
    public function __construct(
        #[Field('The id of the deal.', format: 'uuid')]
        public string $dealId,
        #[Field('The id of the planned invoice.', format: 'uuid')]
        public string $entryId,
    ) {
    }
}

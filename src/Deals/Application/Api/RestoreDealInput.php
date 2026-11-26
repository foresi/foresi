<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A deal brought back from the trash over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'sales.deals.restore',
    method: 'POST',
    path: '/api/v1/sales/deals/{dealId}/restore',
    tool: 'foresi_restore_deal',
    scope: 'sales:write',
    title: 'Restore a deal',
    description: 'Brings a deal back from the trash as it was. Restoring one that is not in the trash changes nothing.',
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class RestoreDealInput
{
    public function __construct(
        #[Field('The id of the deal.', format: 'uuid')]
        public string $dealId,
    ) {
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * A won or lost deal opened again over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'sales.deals.reopen',
    method: 'POST',
    path: '/api/v1/sales/deals/{dealId}/reopen',
    tool: 'foresi_reopen_deal',
    scope: 'sales:write',
    title: 'Reopen a deal',
    description: 'Opens a won or lost deal again at the stage it was at; when and why it was won or lost is forgotten.',
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class ReopenDealInput
{
    public function __construct(
        #[Field('The id of the deal.', format: 'uuid')]
        public string $dealId,
    ) {
    }
}

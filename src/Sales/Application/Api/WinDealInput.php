<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * An open deal won, over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'sales.deals.win',
    method: 'POST',
    path: '/api/v1/sales/deals/{dealId}/win',
    tool: 'foresi_win_deal',
    scope: 'sales:write',
    title: 'Mark a deal won',
    description: 'Marks an open deal won. A won deal is reopened with foresi_reopen_deal.',
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class WinDealInput
{
    public function __construct(
        #[Field('The id of the deal.', format: 'uuid')]
        public string $dealId,
    ) {
    }
}

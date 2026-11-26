<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * The lines of a deal put in a new order over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'sales.deal_lines.reorder',
    method: 'POST',
    path: '/api/v1/sales/deals/{dealId}/lines/reorder',
    tool: 'foresi_reorder_deal_lines',
    scope: 'sales:write',
    title: 'Reorder the lines of a deal',
    description: 'Puts the lines of a deal in the order given. Name every line of the deal once.',
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class ReorderDealLinesInput
{
    /**
     * @param list<mixed> $lineIds
     */
    public function __construct(
        #[Field('The id of the deal.', format: 'uuid')]
        public string $dealId,
        #[Field('The ids of all the lines of the deal, in their new order.', items: 'string')]
        public array $lineIds,
    ) {
    }
}

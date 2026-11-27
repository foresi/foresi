<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A line removed from a deal over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'deals.lines.remove',
    method: 'DELETE',
    path: '/api/v1/deals/{dealId}/lines/{lineId}',
    tool: 'foresi_remove_deal_line',
    scope: 'deals:write',
    title: 'Remove a line from a deal',
    description: 'Removes a line from a deal; the lines after it move up.',
    destructive: true,
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class RemoveDealLineInput
{
    public function __construct(
        #[Field('The id of the deal.', format: 'uuid')]
        public string $dealId,
        #[Field('The id of the line.', format: 'uuid')]
        public string $lineId,
    ) {
    }
}

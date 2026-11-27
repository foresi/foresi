<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * One deal linked to another of the same client over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'deals.links.add',
    method: 'POST',
    path: '/api/v1/deals/{dealId}/links',
    tool: 'foresi_add_deal_link',
    scope: 'deals:write',
    title: 'Link two deals',
    description: 'Records that a deal follows from another deal of the same client. A deal is never linked to itself, and two deals are linked once.',
    status: 201,
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class AddDealLinkInput
{
    public function __construct(
        #[Field('The id of the deal.', format: 'uuid')]
        public string $dealId,
        #[Field('The id of the deal it follows from.', format: 'uuid')]
        public string $toDealId,
        #[Field('How the deals are linked.', enum: ['follows_from'])]
        public string $type = 'follows_from',
    ) {
    }
}

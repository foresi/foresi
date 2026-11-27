<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A link between two deals removed over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'deals.links.remove',
    method: 'DELETE',
    path: '/api/v1/deals/{dealId}/links/{linkId}',
    tool: 'foresi_remove_deal_link',
    scope: 'deals:write',
    title: 'Unlink two deals',
    description: 'Removes a link of the deal to another deal; both deals stay.',
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class RemoveDealLinkInput
{
    public function __construct(
        #[Field('The id of the deal.', format: 'uuid')]
        public string $dealId,
        #[Field('The id of the link.', format: 'uuid')]
        public string $linkId,
    ) {
    }
}

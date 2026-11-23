<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * An open deal moved to another stage over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'sales.deals.change_stage',
    method: 'POST',
    path: '/api/v1/sales/deals/{dealId}/stage',
    tool: 'foresi_change_deal_stage',
    scope: 'sales:write',
    title: 'Move a deal to another stage',
    description: 'Moves an open deal to another stage (stages from foresi_list_deal_stages). A won or lost deal keeps its stage; reopen it first with foresi_reopen_deal.',
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class ChangeDealStageInput
{
    public function __construct(
        #[Field('The id of the deal.', format: 'uuid')]
        public string $dealId,
        #[Field('The id of the stage it moves to.', format: 'uuid')]
        public string $stageId,
    ) {
    }
}
